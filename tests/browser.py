"""Adapted from mb-stern/Waermepumpe aae3f06 (MIT; WPLUX/heat-pump/LICENSE).

Optional integrated browser regression: PHP, Playwright and Chromium are prerequisites.

Run from the repository: python3 tests/browser.py
Only local fixtures are served; no real Symcon or device actions are used.
"""
import base64
import functools
import http.server
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading

from playwright.sync_api import sync_playwright

repository = Path(__file__).resolve().parent.parent
chromium = shutil.which("chromium") or shutil.which("chromium-browser")
if not chromium:
    raise RuntimeError("Chromium is required for this optional browser check")

class QuietHandler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


class LuxtronikFixture:
    """Independent local peer for the protocol seen in the user's Lux.js."""

    def __init__(self):
        self.connections = []
        self.commands = []
        self.empty_updates = False
        self.silent = False
        # Packed bytes cover vertical bits, row boundaries and the last pixel.
        self.image = base64.b64encode(bytes([1] + [0] * 1278 + [128])).decode()

    def connect(self, route):
        index = len(self.connections) + 1
        connection = {'route': route, 'closed': False, 'navigation': False,
                      'display': False, 'menu': f'remote-{index}', 'lcd': f'lcd-{index}'}
        self.connections.append(connection)

        def receive(message):
            self.commands.append(message)
            if message == 'REFRESH':
                if self.silent:
                    return
                if not connection['navigation']:
                    connection['navigation'] = True
                    route.send('<Navigation><item><item id="info"><name>Informationen</name></item>'
                               f'<item id="{connection["menu"]}"><name>Fernsteuerung</name></item>'
                               '</item></Navigation>')
                elif connection['display']:
                    route.send('<Values/>' if self.empty_updates else
                               f'<Values><item id="{connection["lcd"]}"><value>{self.image}</value></item></Values>')
            elif message.startswith('GET;'):
                assert message == 'GET;' + connection['menu'], message
                connection['display'] = True
                route.send('<Content><name>Fernsteuerung</name><item><name>Gerät</name>'
                           f'<item id="{connection["lcd"]}"><name>LCD</name><img>{self.image}</img>'
                           '</item></item></Content>')

        route.on_message(receive)
        route.on_close(lambda code, reason: connection.update(closed=True))


with tempfile.TemporaryDirectory(prefix="wp-browser-") as artifacts:
    subprocess.run(["php", str(repository / "tests/visualization.php")],
                   cwd=repository, check=True,
                   env={**os.environ, "WP_TEST_ARTIFACT_DIR": artifacts})
    Path(artifacts, 'luxtronik-test.html').write_text(
        '<meta charset="utf-8"><h1>Lokale Webinterface-Testseite</h1>',
        encoding='utf-8')
    handler = functools.partial(QuietHandler, directory=artifacts)
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(executable_path=chromium,headless=True,args=['--no-sandbox'])
            for width in [1200,390]:
                page=browser.new_page(viewport={'width':width,'height':850})
                luxtronik=LuxtronikFixture()
                page.route_web_socket(re.compile(r'^ws://127\.0\.0\.1:8214/$'),luxtronik.connect)
                page.add_init_script('''(() => {
                    const NativeWebSocket=window.WebSocket;
                    window.testSocketRequests=[];
                    window.WebSocket=class extends NativeWebSocket {
                        constructor(url, protocols) {
                            super(url,protocols);
                            window.testSocketRequests.push({url,protocols});
                        }
                    };
                })();''')
                errors=[]
                page.on('pageerror',lambda e: errors.append(str(e)))
                page.goto(f'http://127.0.0.1:{server.server_port}/configured.html')
                page.wait_for_timeout(250)
                assert page.locator('#wp-error').text_content()==''
                result=page.evaluate('''() => {
                    const box=document.querySelector('#info > rect');
                    const text=document.querySelector('#textReturnTemperatureValue');
                    const bounds=text.getBBox();
                    return {width:box.getAttribute('width'),actual:document.querySelector('#textReturnTemperatureActualValue').textContent,target:document.querySelector('#textReturnTemperatureSetpointValue').textContent,x:bounds.x,right:bounds.x+bounds.width};
                }''')
                assert result['width']=='650',result
                assert result['actual']=='28.9 °C' and result['target']==' / Soll 30.0 °C',result
                assert result['x']==462 and result['right']<=650.1,result
                payload=page.evaluate('''() => {
                    const script=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                    return JSON.parse(script.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                }''')
                assert page.locator('#wp-view-toggle,#wp-compact-view').count()==0
                # Native checkbox selections must not be limited to the old ten footer slots.
                footer_sample=json.loads(json.dumps(payload))
                for i in range(10):
                    footer_sample['config']['additionalValue'+str(i).zfill(3)]=''
                footer_sample['config']['instanceFooter']=[]
                for i in range(13):
                    key='internalTest'+str(i)
                    footer_sample['config']['instanceFooter'].append({'key':key,'label':'Intern '+str(i),'variableId':99000+i})
                    footer_sample['data'][key]={'value':i,'formatted':str(i)+' °C','binary':False}
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',footer_sample)
                assert page.locator('#gFooter text[id^="textFooterAdditional"]').count()==13
                assert '0 °C' in page.locator('#textFooterAdditional000').text_content()
                assert '12 °C' in page.locator('#textFooterAdditional012').text_content()
                assert page.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.height')>985
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
                # Only an intercepted local WebSocket and a local fallback page are used.
                assert not page.locator('#gSettings').is_visible()
                assert not luxtronik.connections
                web_payload=json.loads(json.dumps(payload))
                web_url=f'http://127.0.0.1:{server.server_port}/luxtronik-test.html'
                web_payload['config']['luxtronikWebInterfaceURL']=web_url
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',web_payload)
                assert page.locator('#gSettings').is_visible()
                assert page.locator('#gSettings text').first.text_content()=='LUX Bedienfeld'
                assert not luxtronik.connections
                page.locator('#gSettings').dispatch_event('click')
                assert page.locator('#wp-luxtronik-dialog').is_visible()
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                assert page.evaluate('document.activeElement.id')=='wp-luxtronik-display'
                assert luxtronik.commands[:3]==['LOGIN;999999','REFRESH','GET;remote-1'],luxtronik.commands
                assert page.evaluate('window.testSocketRequests[0]')=={'url':'ws://127.0.0.1:8214/','protocols':'Lux_WS'}
                pixels=page.locator('#wp-luxtronik-display').evaluate('''e=>{
                    const ctx=e.getContext('2d');
                    return [[0,0],[0,1],[159,63]].map(([x,y])=>Array.from(ctx.getImageData(x,y,1,1).data));
                }''')
                assert pixels==[[255,255,255,255],[0,0,255,255],[255,255,255,255]],pixels
                assert not any(command.startswith('MOVE;') for command in luxtronik.commands)
                before=len(luxtronik.commands)
                page.get_by_role('button',name='Pfeil links',exact=True).click()
                page.get_by_role('button',name='Pfeil rechts',exact=True).click()
                page.locator('#wp-luxtronik-press').click()
                page.locator('#wp-luxtronik-display').focus()
                for key in ['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Enter','Space']:
                    page.keyboard.press(key)
                page.locator('#wp-luxtronik-display').click()
                page.locator('#wp-luxtronik-display').dispatch_event('wheel',{'deltaY':-100})
                page.locator('#wp-luxtronik-display').dispatch_event('wheel',{'deltaY':100})
                expected_moves=[1,0,2,6,1,6,0,6,1,6,0,6,2,6,2,6,2,6,1,0]
                page.wait_for_timeout(50)
                moves=[command for command in luxtronik.commands[before:] if command.startswith('MOVE;')]
                assert moves==['MOVE;'+str(value) for value in expected_moves],moves
                bounds=page.locator('#wp-luxtronik-dialog').bounding_box()
                assert bounds['x']>=0 and bounds['x']+bounds['width']<=width,bounds
                external=page.locator('#wp-luxtronik-external')
                assert external.get_attribute('href')==web_url
                assert external.get_attribute('target')=='_blank'
                with page.expect_popup() as popup:
                    external.click()
                popup.value.wait_for_load_state()
                assert popup.value.get_by_role('heading').text_content()=='Lokale Webinterface-Testseite'
                assert popup.value.evaluate('window.opener===null')
                popup.value.close()
                page.locator('#wp-luxtronik-close').click()
                assert not page.locator('#wp-luxtronik-dialog').is_visible()
                page.wait_for_timeout(50)
                assert luxtronik.connections[-1]['closed']
                assert page.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
                for key in ['Enter','Space']:
                    page.locator('#gSettings').focus()
                    page.keyboard.press(key)
                    assert page.locator('#wp-luxtronik-dialog').is_visible()
                    page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                    page.keyboard.press('Escape')
                    assert not page.locator('#wp-luxtronik-dialog').is_visible()
                    assert page.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
                # Dynamic menu IDs are resolved again on every new connection.
                assert [command for command in luxtronik.commands if command.startswith('GET;')]==[
                    'GET;remote-1','GET;remote-2','GET;remote-3']
                page.locator('#gSettings').dispatch_event('click')
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                # Closing during a held Enter must release it before disconnecting.
                page.locator('#wp-luxtronik-press').hover()
                page.mouse.down()
                page.keyboard.press('Escape')
                page.mouse.up()
                page.wait_for_timeout(50)
                assert [command for command in luxtronik.commands if command.startswith('MOVE;')][-2:]==['MOVE;2','MOVE;6']
                page.locator('#gSettings').dispatch_event('click')
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                # Corrupt LCD data disables controls and clears the previous screen.
                luxtronik.connections[-1]['route'].send('<Values><item id="lcd-5"><value>bad!</value></item></Values>')
                page.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
                assert 'nicht gelesen' in page.locator('#wp-luxtronik-help').text_content()
                assert page.locator('#wp-luxtronik-display').evaluate('e=>e.getContext("2d").getImageData(0,0,1,1).data[3]')==0
                # A reconnect uses the current input, while protocol separators are rejected.
                page.locator('#wp-luxtronik-password').fill('bad;password')
                page.get_by_role('button',name='Erneut verbinden',exact=True).click()
                page.wait_for_function('document.querySelector("#wp-luxtronik-help").textContent.includes("Semikolons")')
                assert 'LOGIN;bad;password' not in luxtronik.commands
                page.locator('#wp-luxtronik-password').fill('999999')
                page.get_by_role('button',name='Erneut verbinden',exact=True).click()
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                luxtronik.connections[-1]['route'].send('<Navigation><item id="info"><name>Informationen</name></item></Navigation>')
                page.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
                assert 'nicht angeboten' in page.locator('#wp-luxtronik-help').text_content()
                page.locator('#wp-luxtronik-close').click()
                # A server disconnect reconnects, resolves the new ID and replays no keys.
                page.locator('#gSettings').dispatch_event('click')
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                connections_before=len(luxtronik.connections)
                commands_before=len(luxtronik.commands)
                luxtronik.connections[-1]['route'].close(code=1001,reason='Simulated idle disconnect')
                page.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
                assert 'Wiederverbindung' in page.locator('#wp-luxtronik-help').text_content()
                page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                assert len(luxtronik.connections)==connections_before+1
                assert 'GET;remote-'+str(connections_before+1) in luxtronik.commands[commands_before:]
                assert not any(command.startswith('MOVE;') for command in luxtronik.commands[commands_before:])
                # Closing while a retry is pending must cancel that retry and all polling.
                luxtronik.connections[-1]['route'].close(code=1001,reason='Simulated second disconnect')
                page.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
                page.locator('#wp-luxtronik-close').click()
                connections_before=len(luxtronik.connections)
                commands_before=len(luxtronik.commands)
                page.wait_for_timeout(1200)
                assert len(luxtronik.connections)==connections_before
                assert len(luxtronik.commands)==commands_before
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',payload)
                assert not page.locator('#gSettings').is_visible()
                target_key=payload['config']['returnTemperatureSetpoint']
                actual_key=payload['config'].get('heatPumpReturnTemperature') or payload['config']['refluxTemperatureHeating']
                # Missing tank sensors must leave no stale or synthetic readings.
                tanks=[
                    ('tankTempWW', 'textTankTempWW', {'Up':85,'Middle':162,'Down':240}),
                    ('tankTempHP', 'textTankTempHP', {'Up':82,'Middle':158,'Down':232}),
                ]
                def tank_readings():
                    return page.evaluate('''() => Object.fromEntries(
                        [...document.querySelectorAll('text[id^="textTankTemp"]')].map(e=>[
                            e.id,{text:e.textContent,display:getComputedStyle(e).display,y:Number(e.getAttribute('y'))}
                        ])
                    )''')
                def update_tanks(p):
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',p)
                for active in [[],['Up'],['Up','Down'],['Up','Middle','Down']]:
                    changed=json.loads(json.dumps(payload))
                    for key_prefix, text_prefix, positions in tanks:
                        for suffix in ['Up','Middle','Down']:
                            if suffix not in active:
                                # Retain old data deliberately: an unlinked config must win.
                                changed['config'][key_prefix+suffix]=''
                    update_tanks(changed)
                    readings=tank_readings()
                    for key_prefix, text_prefix, positions in tanks:
                        for suffix in ['Up','Middle','Down']:
                            reading=readings[text_prefix+suffix]
                            if suffix in active:
                                assert reading['text']=='25.5' and reading['display']!='none',reading
                                assert reading['y']==positions[suffix],reading
                            else:
                                assert reading['text']=='' and reading['display']=='none',reading
                # Boiler loading must not synthesize temperatures from the heating circuit.
                boiler=json.loads(json.dumps(payload))
                boiler['config']['heatingCircuitType1']='underfloor'
                boiler['config']['heatingCircuitType2']='off'
                boiler['config']['heatingCircuitType3']='off'
                boiler['config']['singleCircuitHotWaterTemperatureSwitch']=True
                boiler['config']['wwHeatingValve']='testBoilerValve'
                boiler['data']['testBoilerValve']={'value':True,'formatted':'On'}
                for suffix in ['Up','Middle','Down']:
                    boiler['config']['tankTempWW'+suffix]=''
                update_tanks(boiler)
                for suffix in ['Up','Middle','Down']:
                    assert tank_readings()['textTankTempWW'+suffix]['text']==''
                assert page.locator('#symconBoilerSupplyTemperature,#symconBoilerRefluxTemperature').count()==0
                boiler['config']['tankTempWWMiddle']='testDHWActual'
                boiler['data']['testDHWActual']={'value':48.5,'formatted':'48.5 °C'}
                update_tanks(boiler)
                readings=tank_readings()
                assert '48.5' in readings['textTankTempWWMiddle']['text']
                assert readings['textTankTempWWMiddle']['y']==162
                assert readings['textTankTempWWUp']['text']==readings['textTankTempWWDown']['text']==''
                assert page.locator('#symconBoilerSupplyTemperature,#symconBoilerRefluxTemperature').count()==0
                # A selected sensor may subsequently lose its data or yield no value.
                for missing_value in ['missing',None,'','   ']:
                    changed=json.loads(json.dumps(payload))
                    for key_prefix, _, _ in tanks:
                        for suffix in ['Up','Middle','Down']:
                            key=changed['config'][key_prefix+suffix]
                            if missing_value=='missing':
                                changed['data'].pop(key,None)
                            else:
                                changed['data'][key].update(value=missing_value,formatted='57.2 °C')
                    update_tanks(changed)
                    for reading in tank_readings().values():
                        assert reading['text']=='' and reading['display']=='none',reading
                # Zero is a legitimate measurement; the component preview adds no data.
                zero=json.loads(json.dumps(payload))
                for key_prefix, _, _ in tanks:
                    for suffix in ['Up','Middle','Down']:
                        zero['data'][zero['config'][key_prefix+suffix]].update(value=0,formatted='0.0 °C')
                update_tanks(zero)
                for reading in tank_readings().values():
                    assert reading['text']=='0.0 °C' and reading['display']!='none',reading
                zero['config']['fullConfigurationPreview']=True
                for key_prefix, _, _ in tanks:
                    for suffix in ['Up','Middle','Down']:
                        zero['config'][key_prefix+suffix]=''
                update_tanks(zero)
                for reading in tank_readings().values():
                    assert reading['text']=='' and reading['display']=='none',reading
                update_tanks(payload)
                # Drive real numeric popups and capture only simulated SDK actions.
                page.evaluate('''() => {
                    window.testActions=[];
                    window.requestAction=(ident,value)=>window.testActions.push({ident,payload:JSON.parse(value)});
                }''')
                for function, selector in [
                    ('warmWaterSetpoint', '#gSymconWarmWaterSetpoint'),
                    ('heatingTemperatureCorrection', '#gSymconHeatingCorrection')
                ]:
                    for minimum, maximum, step, value, expected in [
                        (20,80,0.3,80,80),
                        (20.1,80,0.3,80,79.8),
                        (20.1,80,0.3,-100,20.1),
                        (-10,10,0.3,10,9.8),
                        (-10,10,0.3,-11,-10),
                        (20,80,0.5,50.5,50.5),
                        (0,10,1,4,4),
                    ]:
                        controls=json.loads(json.dumps(payload['controls']))
                        controls[function].update(min=minimum,max=maximum,step=step,currentValue=value)
                        page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',controls)
                        page.locator(selector).dispatch_event('click')
                        field=page.locator('#wp-mode-menu input')
                        field.fill(str(value))
                        page.get_by_role('button',name='Übernehmen',exact=True).dispatch_event('click')
                        action=page.evaluate('window.testActions.at(-1)')
                        assert action=={'ident':'SetNumericControl','payload':{'function':function,'value':expected}},action
                        assert minimum<=action['payload']['value']<=maximum,action
                        # An empty correction must not turn into zero or a boundary.
                        page.locator(selector).dispatch_event('click')
                        field.fill('')
                        before=page.evaluate('window.testActions.length')
                        page.get_by_role('button',name='Übernehmen',exact=True).dispatch_event('click')
                        assert page.evaluate('window.testActions.length')==before
                        page.keyboard.press('Escape')
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',payload['controls'])
                # Exact-Ident readings feed the display and schematic compressor speed.
                def compressor_reading(speed, running=True, configure_speed=True,
                                       configure_running=True, missing_speed=False):
                    sample=json.loads(json.dumps(payload))
                    speed_key=sample['config']['compressorValue']
                    running_key=sample['config']['compressorRunning']
                    sample['data'][speed_key]['value']=speed
                    sample['data'][speed_key]['formatted']=f'{speed} rpm'
                    sample['data'][running_key]['value']=running
                    if not configure_speed: sample['config']['compressorValue']=''
                    if not configure_running: sample['config']['compressorRunning']=''
                    if missing_speed: sample['data'].pop(speed_key)
                    condensation_key=sample['config']['condenserTemperature']
                    sample['data'][condensation_key]['value']=42.5
                    sample['data'][condensation_key]['formatted']='42.5 °C'
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',sample)
                    return page.locator('#pathCompressor').evaluate(
                        'e=>({name:getComputedStyle(e).animationName,duration:parseFloat(getComputedStyle(e).animationDuration)})')

                for speed, expected_duration in [(1500,2.0),(3000,1.0),(1,3.0),(100000,0.6),('1500,0',2.0)]:
                    rotor=compressor_reading(speed)
                    assert rotor=={'name':'symcon-modern-rotate','duration':expected_duration},(speed,rotor)
                    assert page.locator('#textCompressorSpeedValue').text_content()==f'{speed} rpm'
                    assert page.locator('#textCondenserTemperature').text_content()=='42.5 °C'
                for speed in [0,-1]:
                    assert compressor_reading(speed)['name']=='none'
                    assert page.locator('#gRefrigerantCircuit .wp-live-flow').count()==0
                assert compressor_reading(3000,running=False)['name']=='none'
                for speed in [None,'','unavailable',False]:
                    assert compressor_reading(speed)=={'name':'symcon-modern-rotate','duration':1.4}
                assert compressor_reading(3000,missing_speed=True)['duration']==1.4
                assert compressor_reading(3000,configure_speed=False)['duration']==1.4
                assert compressor_reading(3000,configure_running=False)['duration']==1.0
                assert page.locator('#gRefrigerantCircuit .wp-live-flow').count()>0
                assert compressor_reading(0,configure_speed=False,configure_running=False)['name']=='none'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)

                # Actual running variables drive overlays; updates keep their identity.
                assert page.locator('.wp-live-flow').count() > 0
                page.evaluate("document.querySelector('.wp-live-flow').dataset.testIdentity='retained'")
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',payload)
                assert page.locator('.wp-live-flow[data-test-identity="retained"]').count() == 1
                for theme in ['light', 'dark']:
                    page.evaluate("""(theme) => {
                        const dark=theme==='dark';
                        document.documentElement.style.setProperty('--content-color',dark?'#e6edf4':'#1f2937');
                        document.documentElement.style.setProperty('--card-background-color',dark?'#18222f':'#f8fafc');
                        document.documentElement.style.setProperty('--divider-color',dark?'#354255':'#d8e1ea');
                        document.documentElement.style.setProperty('background',dark?'#111923':'#f0f4f8','important');
                        document.body.style.setProperty('background',dark?'#111923':'#f0f4f8','important');
                    }""",theme)
                    page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',payload)
                    page.wait_for_timeout(250)  # Allow the theme transition to finish.
                    screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                    if screenshot_dir:
                        Path(screenshot_dir).mkdir(parents=True,exist_ok=True)
                        page.screenshot(path=str(Path(screenshot_dir)/f'{theme}-{width}.png'))
                    assert page.locator('#wp-error').text_content()==''
                page.emulate_media(reduced_motion='reduce')
                assert page.locator('.wp-live-flow').first.evaluate("e=>getComputedStyle(e).animationName")=='none'
                assert page.locator('#pathCompressor').evaluate("e=>getComputedStyle(e).animationName")=='none'
                idle=json.loads(json.dumps(payload))
                for key in ['compressorRunning','storageChargingPumpRunning',
                            'heatingCircuitPumpRunning','heatingCircuitPumpRunning2',
                            'heatingCircuitPumpRunning3','thermalSolarPumpSpeed']:
                    entity=idle['config'].get(key)
                    if entity in idle['data']: idle['data'][entity]['value']=0
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',idle)
                assert page.locator('.wp-live-flow').count()==0
                payload['data'][target_key]['formatted']='-123.45 °C langer Profiltext'
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',payload)
                assert page.evaluate('document.querySelector("#textReturnTemperatureValue").getBBox().x+document.querySelector("#textReturnTemperatureValue").getBBox().width')<=650.1
                payload['data'].pop(target_key)
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',payload)
                assert page.locator('#textReturnTemperatureSetpointValue').text_content()==''
                payload['data'].pop(actual_key)
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',payload)
                assert page.locator('#textReturnTemperatureValue').text_content()==''
                assert not errors,errors
                print(json.dumps({'viewport':width,**result,'updates':'PASS'}))
                page.close()
            # Component-only configuration resolves three native mixed circuits automatically.
            for width in [1200,390]:
                mixed=browser.new_page(viewport={'width':width,'height':850})
                mixed.goto(f'http://127.0.0.1:{server.server_port}/mixed-circuits.html')
                assert mixed.locator('#wp-error').text_content()==''
                for index in [1,2,3]:
                    suffix='' if index==1 else str(index)
                    assert mixed.locator('#textHeatingCircuitName'+str(index)).text_content()=='Mischkreis '+str(index)
                    assert str(25+index*5) in mixed.locator('#textSupplyTemperatureHeating'+suffix).text_content()
                    assert mixed.locator('#textRefluxTemperatureHeating'+suffix).text_content()==''
                    assert mixed.locator('#textRefluxTemperatureHeating'+suffix).evaluate('e=>getComputedStyle(e).display')=='none'
                assert '40' in mixed.locator('#textReturnTemperatureActualValue').text_content()
                mixed.close()

            # Integration of PHP's direct Ident resolver and actual VM_UPDATE payload.
            live_update=json.loads(Path(artifacts,'live-ident-update.json').read_text())
            for width in [1200,390]:
                live_page=browser.new_page(viewport={'width':width,'height':850})
                live_errors=[]
                live_page.on('pageerror',lambda e: live_errors.append(str(e)))
                live_page.goto(f'http://127.0.0.1:{server.server_port}/live-ident.html')
                resolved=live_page.evaluate("""() => {
                    const script=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                    const p=JSON.parse(script.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                    return Object.fromEntries(['operatingStatus','supplyTemperature','fanSpeed','outdoorTemperature','compressorValue','condenserTemperature'].map(key=>[key,p.data[key].variableId]));
                }""")
                assert resolved=={key:live_update['data'][key]['variableId'] for key in resolved},resolved
                assert live_page.locator('#textCompressorSpeedValue').text_content()=='3000 rpm'
                assert live_page.locator('#textCondenserTemperature').text_content()=='42.5 °C'
                assert live_page.locator('#pathCompressor').evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==1.0
                live_page.evaluate('(p)=>window.handleMessage(p)',live_update)
                assert live_page.locator('#textCompressorSpeedValue').text_content()=='1500 rpm'
                assert live_page.locator('#pathCompressor').evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==2.0
                assert not live_errors,live_errors
                live_page.close()
            alias_update=json.loads(Path(artifacts,'alias-ident-update.json').read_text())
            for width in [1200,390]:
                alias_page=browser.new_page(viewport={'width':width,'height':850})
                alias_page.goto(f'http://127.0.0.1:{server.server_port}/alias-ident.html')
                assert alias_page.locator('#textCompressorSpeedValue').text_content()=='50 Hz'
                assert alias_page.locator('#textCondenserTemperature').text_content()=='42.5 °C'
                assert alias_page.locator('#pathCompressor').evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==2.0
                alias_page.evaluate('(p)=>window.handleMessage(p)',alias_update)
                assert alias_page.locator('#textCompressorSpeedValue').text_content()=='80 Hz'
                assert alias_page.locator('#pathCompressor').evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==1.25
                alias_page.close()
            print(json.dumps({'direct_ident':'PASS','ident_variable_updates':'PASS','reported_aliases':'PASS','frequency_animation':'PASS'}))

            # A live switch to SHI/read-only must close an already connected device panel.
            readonly=browser.new_page()
            readonly_peer=LuxtronikFixture()
            readonly.route_web_socket(re.compile(r'^ws://127\.0\.0\.1:8214/$'),readonly_peer.connect)
            readonly.goto(f'http://127.0.0.1:{server.server_port}/configured.html')
            readonly.evaluate('(url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
            readonly.locator('#gSettings').dispatch_event('click')
            readonly.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
            readonly.evaluate('window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:""}})')
            assert not readonly.locator('#wp-luxtronik-dialog').is_visible()
            assert readonly.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
            readonly.wait_for_timeout(100)
            assert readonly_peer.connections[-1]['closed']
            assert not any(command.startswith('MOVE;') for command in readonly_peer.commands)
            readonly.close()

            # Exercise inactivity and retry limits without waiting for real timeouts.
            liveness=browser.new_page()
            liveness.clock.install()
            peer=LuxtronikFixture()
            liveness.route_web_socket(re.compile(r'^ws://127\.0\.0\.1:8214/$'),peer.connect)
            liveness.goto(f'http://127.0.0.1:{server.server_port}/configured.html')
            liveness.evaluate('(url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
            liveness.locator('#gSettings').dispatch_event('click')
            liveness.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
            peer.empty_updates=True
            for _ in range(4):
                liveness.clock.fast_forward(5000)
                liveness.wait_for_timeout(50)
                assert not liveness.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
                assert len(peer.connections)==1
            peer.silent=True
            liveness.clock.fast_forward(16000)
            liveness.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
            assert 'Wiederverbindung' in liveness.locator('#wp-luxtronik-help').text_content()
            peer.silent=False
            liveness.clock.fast_forward(1000)
            liveness.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
            assert len(peer.connections)==2
            for delay in [3000,10000]:
                peer.connections[-1]['route'].close(code=1001,reason='Simulated repeated disconnect')
                liveness.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
                liveness.clock.fast_forward(delay+1)
                liveness.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
            assert len(peer.connections)==4
            peer.connections[-1]['route'].close(code=1001,reason='Retry limit check')
            liveness.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
            assert 'Bitte erneut verbinden' in liveness.locator('#wp-luxtronik-help').text_content()
            liveness.clock.fast_forward(60000)
            assert len(peer.connections)==4
            assert not any(command.startswith('MOVE;') for command in peer.commands)
            liveness.get_by_role('button',name='Erneut verbinden',exact=True).click()
            liveness.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
            assert len(peer.connections)==5
            peer.connections[-1]['route'].send('<Navigation><item id="locked"><name>Fernsteuerung</name><readOnly>1</readOnly></item></Navigation>')
            liveness.wait_for_function('document.querySelector("#wp-luxtronik-controls").disabled')
            assert 'nicht zur Bedienung freigegeben' in liveness.locator('#wp-luxtronik-help').text_content()
            assert 'GET;locked' not in peer.commands
            liveness.locator('#wp-luxtronik-close').click()
            liveness.close()
            print(json.dumps({'luxtronik':'PASS','idle_heartbeat':'PASS','reconnect_limit':'PASS'}))
            # HTTPS Symcon must offer a separate tab instead of a blocked ws connection.
            secure=browser.new_page()
            secure.route('https://symcon.test/**', lambda route: route.fulfill(
                content_type='text/html', body=Path(artifacts,'configured.html').read_text()))
            secure.goto('https://symcon.test/configured.html')
            secure.evaluate('(url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
            secure.locator('#gSettings').dispatch_event('click')
            assert secure.locator('#wp-luxtronik-dialog').is_visible()
            assert secure.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
            assert 'HTTP' in secure.locator('#wp-luxtronik-help').text_content()
            assert secure.locator('#wp-luxtronik-external').get_attribute('href')==web_url
            # SDK content can also be embedded through about:srcdoc in an HTTPS page.
            secure.evaluate('''(html)=>{
                document.body.replaceChildren();
                const frame=document.createElement('iframe');
                frame.id='sdk';
                frame.srcdoc=html;
                document.body.append(frame);
            }''',Path(artifacts,'configured.html').read_text())
            sdk=secure.frame_locator('#sdk')
            sdk.locator('#wp-card svg').wait_for(state='attached')
            sdk.locator('body').evaluate('(body,url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
            sdk.locator('#gSettings').dispatch_event('click')
            assert sdk.locator('#wp-luxtronik-dialog').is_visible()
            assert sdk.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
            assert 'HTTP' in sdk.locator('#wp-luxtronik-help').text_content()
            secure.close()
            browser.close()
    finally:
        server.shutdown()
        server.server_close()
        thread.join()
