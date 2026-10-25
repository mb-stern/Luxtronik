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
        self.eev_values = ('34.8 %','100.0 %')
        self.output_xml = None
        self.information_xml = None
        self.inline_outputs = False
        # Packed bytes cover vertical bits, row boundaries and the last pixel.
        self.image = base64.b64encode(bytes([1] + [0] * 1278 + [128])).decode()

    def connect(self, route):
        index = len(self.connections) + 1
        connection = {'route': route, 'closed': False, 'navigation': False,
                      'display': False, 'menu': f'remote-{index}', 'lcd': f'lcd-{index}', 'commands':[]}
        self.connections.append(connection)

        def receive(message):
            self.commands.append(message)
            connection['commands'].append(message)
            if message == 'REFRESH':
                if self.silent:
                    return
                if not connection['navigation']:
                    connection['navigation'] = True
                    information = '<item id="info"><name>Informationen</name></item>'
                    if self.inline_outputs:
                        information = '<item id="info"><name>Informationen</name><item><name>Ausgänge</name><item id="inline-eev"><name>EEV Heizen</name><value>0.0 %</value></item></item></item>'
                    route.send('<Navigation><item>' + information +
                               f'<item id="{connection["menu"]}"><name>Fernsteuerung</name></item>'
                               '</item></Navigation>')
                elif connection['display']:
                    route.send('<Values/>' if self.empty_updates else
                               f'<Values><item id="{connection["lcd"]}"><value>{self.image}</value></item></Values>')
            elif message.startswith('GET;'):
                if message=='GET;info':
                    route.send(self.information_xml if self.information_xml is not None else
                        f'<Content id="info"><name>Informationen</name><item id="outputs-{index}"><name>Ausgänge</name></item></Content>')
                    return
                if message==f'GET;outputs-{index}':
                    route.send(self.output_xml if self.output_xml is not None else
                        f'<Content id="outputs-{index}"><name>Ausgänge</name><item id="hup-{index}"><name>HUP</name><value>19.5 %</value></item>'
                        f'<item id="eev-heat-{index}"><name>EEV Heizen</name><value>{self.eev_values[0]}</value></item>'
                        f'<item id="eev-cool-{index}"><name>EEV Kühlen</name><value>{self.eev_values[1]}</value></item></Content>')
                    return
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
    Path(artifacts, 'icons.js').write_text('// Symcon provides the real icon library at runtime.\n')
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
                result={'panel':'status, COP/JAZ, heating and input power'}
                assert page.locator('#info > rect').get_attribute('width')=='650'
                assert page.locator('#info #textHPStatus').count()==1
                assert page.locator('#heatPump #textHPStatus').count()==0
                assert 'Heizleistung' in page.locator('#info').text_content()
                assert 'Eingangsleistung' in page.locator('#info').text_content()
                assert page.locator('#textSupplyTemperatureValue,#textReturnTemperatureValue').count()==0
                payload=page.evaluate('''() => {
                    const script=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                    return JSON.parse(script.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                }''')
                # OpenWeather codes replace the original sun without remote images.
                assert page.locator('#gOutdoorWeatherIcon').count()==1
                assert page.locator('#gOutdoorWeatherIcon circle').count()==1
                for kind in ['01','02','03','04','09','10','11','13','50']:
                    for phase in ['d','n']:
                        code=kind+phase
                        weather_data=json.loads(json.dumps(payload['data']))
                        weather_data['weatherIcon']={'value':code,'formatted':code}
                        page.evaluate('(data)=>window.handleMessage({type:"update",data})',weather_data)
                        icon=page.locator('#gOutdoorWeatherIcon')
                        assert icon.get_attribute('data-weather-code')==code
                        assert ('Nacht' if phase=='n' else 'Tag') in icon.get_attribute('aria-label')
                        assert icon.locator('image,foreignObject,script').count()==0
                        bounds=icon.evaluate('e=>{const b=e.getBBox();return {x:b.x,y:b.y,right:b.x+b.width,bottom:b.y+b.height};}')
                        assert bounds['x']>=0 and bounds['right']<=100 and bounds['y']>=0 and bounds['bottom']<=100,bounds
                        if code=='01n': assert icon.locator('circle').count()==0
                        if code=='10d': assert 'Regen' in icon.get_attribute('aria-label')
                # Raw codes and profile association captions are supported, including raw zero.
                for value,formatted,expected in [(' 10D ','','10d'),(0,'01n','01n'),('invalid','',''),('<img src=x onerror=alert(1)>','','')]:
                    weather_data['weatherIcon']={'value':value,'formatted':formatted}
                    page.evaluate('(data)=>window.handleMessage({type:"update",data})',weather_data)
                    assert page.locator('#gOutdoorWeatherIcon').get_attribute('data-weather-code')==expected
                    assert page.locator('#gOutdoorWeatherIcon image,#gOutdoorWeatherIcon foreignObject').count()==0
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',payload['data'])
                assert page.locator('#gOutdoorWeatherIcon').get_attribute('data-weather-code')==''
                assert page.locator('#gOutdoorWeatherIcon circle').count()==1
                assert page.locator('#wp-view-toggle,#wp-compact-view').count()==0
                assert page.locator('#gHeatingManifold').count()==0
                assert 'Verteiler' not in page.locator('#wp-card svg').text_content()
                # The BUP command changes the actual valve path; no measured position is inferred.
                valve=json.loads(json.dumps(payload))
                key=valve['config']['wwHeatingValve']
                for hotwater,path in [(True,'M-16 0H16'),(False,'M-16 0H0V-16')]:
                    valve['data'][key]['value']=hotwater
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',valve)
                    assert page.locator('#switchValveFlow').get_attribute('d')==path
                valve['config']['wwHeatingValve']=''
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',valve)
                assert page.locator('#switchValveFlow').get_attribute('data-target')=='unknown'
                assert page.locator('#switchValveFlow').get_attribute('d')==''
                assert page.locator('#textWWHeatingValveState').count()==0
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
                # EEV mode selection uses engineering percentages; unknown values stay unknown.
                eev=json.loads(json.dumps(payload))
                eev['config'].update(eevHeatingOpening='testEEVHeating',eevCoolingOpening='testEEVCooling',heatingPumpCoolingMode='testCoolingMode')
                eev['data'].update(testEEVHeating={'value':52.5},testEEVCooling={'value':100},testCoolingMode={'value':False})
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',eev)
                assert page.locator('#textExpansionValveOpening').text_content()=='EEV Heizen: 52.5 %'
                eev['data']['testCoolingMode']['value']=True
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',eev['data'])
                assert page.locator('#textExpansionValveOpening').text_content()=='EEV Kühlen: 100.0 %'
                eev['data']['testEEVCooling']['value']=1000
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',eev['data'])
                assert page.locator('#textExpansionValveOpening').text_content()=='EEV Kühlen: —'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
                assert page.locator('#gFooter').count()==0
                assert page.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.height')==925
                # The narrow layout leaves enough room for straight pipes, mixers and pump symbols.
                narrow=json.loads(json.dumps(payload))
                narrow['config'].update(tankHP=False,thermalSolarAvailable=False,luxtronikWebInterfaceURL=f'http://127.0.0.1:{server.server_port}/luxtronik-test.html')
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',narrow)
                edges=page.evaluate("()=>{const b=document.querySelector('#gSettings rect').getBoundingClientRect(),h=document.querySelector('#gHK1 > rect.pn').getBoundingClientRect();return {button:b.right,card:h.right};}")
                assert edges['card']>=edges['button']-1,edges
                assert page.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.width')<1570
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
                # Only an intercepted local WebSocket and a local fallback page are used.
                assert not page.locator('#gSettings').is_visible()
                assert not luxtronik.connections
                web_payload=json.loads(json.dumps(payload))
                web_url=f'http://127.0.0.1:{server.server_port}/luxtronik-test.html'
                web_payload['config']['luxtronikWebInterfaceURL']=web_url
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',web_payload)
                assert page.locator('#gSettings').is_visible()
                assert page.locator('#gSettings text').all_text_contents()==['LUX','Bedienfeld']
                lux_layout=page.locator('#gSettings').evaluate('''e=>{
                    const texts=[...e.querySelectorAll('text')];
                    return {width:Number(e.querySelector('rect').getAttribute('width')),
                        x:texts.map(t=>Number(t.getAttribute('x'))), y:texts.map(t=>Number(t.getAttribute('y')))};
                }''')
                assert lux_layout['width']<=190 and lux_layout['x']==[18,18] and lux_layout['y']==[45,78],lux_layout
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
                # DHW shows the measured value; the setpoint remains in its top control only.
                assert page.locator('#textDHWIst').text_content()=='25.5 °C'
                assert page.locator('#textDHWSoll').count()==0
                assert page.locator('#main #textHeatingFlow').text_content()=='607 l/h'
                assert page.locator('#gHeatPumpFlow > text').count()==1
                assert page.locator('#heatPump #textWPSupplyTemperature').text_content()=='VL: 25.5 °C'
                assert page.locator('#textHeatingFlow').get_attribute('x')=='680'
                assert page.locator('#textHeatingFlow').get_attribute('y')=='580.5'
                assert 'Ansteuerung' not in page.locator('#textHeatingPump1').text_content()
                small_labels=page.evaluate('''()=>{
                    const svg=document.querySelector('#wp-card svg');
                    const minimum=parseFloat(getComputedStyle(svg.querySelector('#textEvaporatorPressure')).fontSize);
                    return [...svg.querySelectorAll('text,tspan')].filter(e=>getComputedStyle(e).display!=='none' && parseFloat(getComputedStyle(e).fontSize)<minimum).map(e=>e.id||e.textContent);
                }''')
                assert small_labels==[],small_labels
                for suffix in ['Up','Middle','Down']:
                    assert page.locator('#textTankTempWW'+suffix).count()==0
                assert page.locator('#symconBoilerSupplyTemperature,#symconBoilerRefluxTemperature').count()==0
                assert page.locator('[id^="textHeatingReturnTarget"],[id^="textRefluxTemperatureHeating"]').count()==0
                assert page.locator('#heatPump #textWPReturnTemperature').text_content()=='RL: 28.9 °C'
                assert page.locator('#textWPReturnTarget').text_content()=='RL Soll: 30.0 °C'
                sizes=page.evaluate("()=>['textWPSupplyTemperature','textWPReturnTemperature','textWPReturnTarget'].map(id=>getComputedStyle(document.getElementById(id)).fontSize)")
                assert len(set(sizes))==1,sizes
                geometry=page.evaluate("""()=>{
                    const b=id=>document.getElementById(id).getBoundingClientRect();
                    const point=(selector,end)=>{
                        const e=document.querySelector(selector),p=e.getPointAtLength(end?e.getTotalLength():0);
                        return new DOMPoint(p.x,p.y).matrixTransform(e.getScreenCTM());
                    };
                    return {panel:b('heatPumpPanel').toJSON(),values:b('gHeatPumpTemperatures').toJSON(),valve:b('switchValve').toJSON(),
                        tank:document.querySelector('#gDHWTankBody > rect[height="260"]').getBoundingClientRect().toJSON(),
                        hotY:point('#main > path.hot',true).y,coldY:point('#main > path.cold',false).y};
                }""")
                assert geometry['panel']['x']<geometry['values']['x']<geometry['values']['right']<geometry['panel']['right'],geometry
                assert geometry['panel']['y']<geometry['values']['y']<geometry['values']['bottom']<geometry['panel']['bottom'],geometry
                assert abs((geometry['hotY']-geometry['panel']['y'])-(geometry['panel']['bottom']-geometry['coldY']))<1,geometry
                assert abs((geometry['tank']['y']+geometry['tank']['bottom'])/2-(geometry['panel']['y']+geometry['panel']['bottom'])/2)<1,geometry
                assert page.locator('#gHeatPumpTemperatures text').count()==3
                align=page.evaluate("""()=>{
                    const n=document.getElementById('textHeatingCircuitName1'),v=document.getElementById('textSupplyTemperatureHeating');
                    const a=n.getBoundingClientRect(),b=v.getBoundingClientRect();
                    return {nameX:a.x,valueX:b.x,nameBottom:a.bottom,valueTop:b.top,nameFont:getComputedStyle(n).fontSize,valueFont:getComputedStyle(v).fontSize};
                }""")
                assert abs(align['nameX']-align['valueX'])<1 and align['nameBottom']<align['valueTop'],align
                assert align['nameFont']==align['valueFont'],align

                assert page.locator('#textWPSupplyTemperatureLabel,#textWPReturnTemperatureLabel,#textWPReturnTargetLabel').count()==0
                assert page.locator('#switchValve').get_attribute('transform')=='translate(825 592.5)'
                assert page.locator('#main > path.hot').get_attribute('d')=='M650 680H670V592.5H825'
                assert page.locator('#main > path.cold').get_attribute('d')=='M790 762.5H670V705H650'
                assert page.locator('#gDHW > path.hot').get_attribute('d')=='M826 638H900'
                assert float(page.locator('#textWPSupplyTemperature').get_attribute('y'))>592.5
                assert float(page.locator('#textWPReturnTemperature').get_attribute('y'))<762.5


                assert page.locator('#textHeaterRodStatus,#gHeaterRodWaves').count()==0
                assert page.locator('#pathBoilerHeatBolt').get_attribute('class')=='wp-boiler-heater-active'
                assert page.locator('#textBoilerHeaterPower').text_content()=='0.08 kW'
                assert page.locator('#textHeaterButtonState').text_content()=='0.08 kW'
                changed=json.loads(json.dumps(payload))
                changed['data']['heaterRodTotalPower']['value']=0
                changed['data'][changed['config']['inputPower']]['watts']=1800
                changed['controls']['warmWaterSetpoint'].update(currentValue=52.5,formattedValue='52.5 °C')
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert page.locator('#textInputPower').text_content()=='1.80 kW'
                assert page.locator('#textDHWSoll').count()==0
                assert page.locator('#gSymconWarmWaterSetpointValue').text_content()=='52.5 °C'
                assert page.locator('#pathBoilerHeatBolt').get_attribute('class')==''
                assert page.locator('#textBoilerHeaterPower').text_content()=='0.00 kW'
                assert page.locator('#textHeaterButtonState').text_content()=='0.00 kW'
                changed['data']['heaterRodTotalPower']['value']=3500
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',changed['data'])
                assert page.locator('#textHeaterButtonState').text_content()=='3.50 kW'
                changed['data']['heaterRodTotalPower']['value']=None
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',changed['data'])
                assert page.locator('#textHeaterButtonState').text_content()=='—'
                changed['data'].pop('heaterRodTotalPower')
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',changed['data'])
                assert page.locator('#textHeaterButtonState').text_content()=='—'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
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
                # External heater actions use configured numbers; no object IDs come from the browser.
                page.evaluate('(url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
                order=page.evaluate('''()=>['gSymconHeatingCorrection','gHeaterControlButton','gSettings'].map(id=>{
                    const r=document.querySelector('#'+id).getBoundingClientRect();return {x:r.x,right:r.right};
                })''')
                assert order[0]['right']<order[1]['x'] and order[1]['right']<order[2]['x'],order
                page.locator('#gHeaterControlButton').dispatch_event('click')
                assert page.locator('#wp-mode-menu [data-heater="1"][data-value="true"]').text_content()=='Ein'
                page.locator('#wp-mode-menu [data-heater="1"][data-value="true"]').click()
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':1,'value':True}}
                heater_controls=json.loads(json.dumps(payload['controls']))
                heater_controls['heaterSwitches'][0]['currentValue']=True
                heater_controls['heaterSwitches'][0]['enabled']=True
                heater_controls['heaterSwitches'][2]['configured']=False
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',heater_controls)
                assert page.locator('#textHeaterButtonState').text_content()=='0.08 kW'
                page.locator('#gHeaterControlButton').focus()
                page.keyboard.press('Enter')
                assert page.locator('#wp-mode-menu [data-heater="1"][data-value="false"]').text_content()=='Aus'
                assert page.locator('#wp-mode-menu [data-heater="3"]').first.is_disabled()
                before=page.evaluate('window.testActions.length')
                page.locator('#wp-mode-menu [data-heater="3"]').first.dispatch_event('click')
                assert page.evaluate('window.testActions.length')==before
                page.locator('#wp-mode-menu [data-heater="1"][data-value="false"]').click()
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':1,'value':False}}
                heater_controls['heaterSwitches'][1].update(type=1,boolean=False,enabled=None,currentValue=20,stateLabel='Automatik',options=[{'value':10,'name':'Aus'},{'value':20,'name':'Automatik'},{'value':30,'name':'Ein'}])
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',heater_controls)
                page.locator('#gHeaterControlButton').dispatch_event('click')
                assert page.locator('#wp-mode-menu [data-heater="2"][data-value="20"]').text_content()=='Automatik'
                page.locator('#wp-mode-menu [data-heater="2"][data-value="30"]').click()
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':2,'value':30}}
                heater_controls['heaterSwitches'][1].update(currentValue=1,stateLabel='Automatik',options=[{'value':0,'name':'Aus','role':'off'},{'value':2,'name':'Ein','role':'on'}])
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',heater_controls)
                page.locator('#gHeaterControlButton').dispatch_event('click')
                assert page.locator('#wp-mode-menu [data-heater="2"][data-value="1"]').count()==0
                assert page.locator('#wp-mode-menu [data-heater="2"][data-value="2"]').text_content()=='Einschalten: Ein'
                page.locator('#wp-mode-menu [data-heater="2"][data-value="2"]').click()
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':2,'value':2}}
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,controls:p.controls})',payload)
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
                assert compressor_reading(3000,running=False)['name']=='symcon-modern-rotate'
                for speed in [None,'','unavailable',False]:
                    assert compressor_reading(speed)=={'name':'symcon-modern-rotate','duration':1.4}
                assert compressor_reading(3000,missing_speed=True)['duration']==1.4
                assert compressor_reading(3000,configure_speed=False)['duration']==1.4
                assert compressor_reading(3000,configure_running=False)['duration']==1.0
                assert page.locator('#gRefrigerantCircuit .wp-live-flow').count()>0
                assert compressor_reading(0,configure_speed=False,configure_running=False)['name']=='none'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)

                # Pump command and measured l/h have independent schematic speed scales.
                def pump_reading(percent, flow=600, running=True, target=1):
                    sample=json.loads(json.dumps(payload))
                    pump_key=sample['config']['heatingCircuitPumpValue']
                    sample['data'][pump_key]={'value':percent,'formatted':f'{percent} %'}
                    sample['data'][sample['config']['heatingCircuitPumpRunning']]['value']=running
                    for mixer in sample['config']['mixers']:
                        if mixer['number']==1:
                            mixer['circuit']=target
                            sample['data'][mixer['pump']]['value']=running
                        elif target==2 and mixer['circuit']==2:
                            mixer['circuit']=1
                    sample['config']['heatingFlow']='testFlowSpeed'
                    sample['data']['testFlowSpeed']={'value':flow,'formatted':f'{flow} l/h'}
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',sample)
                    return page.locator(f'#hkPumpRotor{target}').evaluate('''e=>({
                        name:getComputedStyle(e).animationName,duration:parseFloat(getComputedStyle(e).animationDuration)})''')

                assert pump_reading(25)=={'name':'symcon-modern-rotate','duration':2.39}
                slow_flow=page.locator('#gHK1 > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')
                assert slow_flow==5.6
                assert pump_reading(75)=={'name':'symcon-modern-rotate','duration':1.16}
                assert page.locator('#gHK1 > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')<slow_flow
                # The primary flow follows its actual measurement, independently of %.
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==2.8
                pump_reading(25,flow=300)
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==5.6
                pump_reading(25,flow=1200)
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==1.4
                assert pump_reading(0)['name']=='none'
                assert page.locator('#gHK1 > .wp-live-flow').count()==0
                assert pump_reading(75,running=False)['name']=='none'
                assert page.locator('#gHK1 > .wp-live-flow').count()==0
                for missing in [None,'','unavailable',False,-1]:
                    rotor=pump_reading(missing)
                    assert rotor['name']=='symcon-modern-rotate' and 1.7<rotor['duration']<1.8,(missing,rotor)
                assert pump_reading('75,0',flow=None)['duration']==1.16
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==1.87
                pump_reading(75,flow=0)
                assert page.locator('#main > .wp-live-flow').count()==0
                assert pump_reading(25,target=2)['duration']==2.39
                assert pump_reading(75,target=2)['duration']==1.16
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)

                # Primary heating water still flows through the condenser when the compressor stops.
                hydraulic=json.loads(json.dumps(payload))
                for key in ['compressorValue','compressorRunning']:
                    entity=hydraulic['config'].get(key)
                    if entity in hydraulic['data']: hydraulic['data'][entity]['value']=0
                hydraulic['config']['heatingFlow']='testPrimaryFlow'
                hydraulic['data']['testPrimaryFlow']={'value':612,'formatted':'612 l/h'}
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',hydraulic)
                assert page.locator('#main > .wp-live-flow').count()==2
                assert page.locator('#gRefrigerantCircuit .wp-live-flow').count()==0
                hydraulic['data']['testPrimaryFlow']['value']=0
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',hydraulic)
                assert page.locator('#main > .wp-live-flow').count()==0
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
                assert page.locator('#hkPumpRotor1').evaluate("e=>getComputedStyle(e).animationName")=='none'
                idle=json.loads(json.dumps(payload))
                for key in ['compressorValue','compressorRunning','storageChargingPumpRunning','heatingFlow',
                            'heatingCircuitPumpRunning','heatingCircuitPumpRunning2',
                            'heatingCircuitPumpRunning3','thermalSolarPumpSpeed','thermalSolarPump']:
                    entity=idle['config'].get(key)
                    if entity in idle['data']: idle['data'][entity]['value']=0
                for mixer in idle['config']['mixers']:
                    if mixer['pump'] in idle['data']: idle['data'][mixer['pump']]['value']=False
                page.evaluate('(p) => window.handleMessage({type:"update",data:p.data,controls:p.controls})',idle)
                assert page.locator('.wp-live-flow').count()==0
                assert not errors,errors
                print(json.dumps({'viewport':width,**result,'updates':'PASS'}))
                page.close()
            # Three original heating places remain separate from the native mixer telemetry.
            for width in [1200,390]:
                mixed=browser.new_page(viewport={'width':width,'height':850})
                errors=[]
                mixed.on('pageerror',lambda e: errors.append(str(e)))
                mixed.goto(f'http://127.0.0.1:{server.server_port}/mixed-circuits.html')
                assert mixed.locator('#wp-error').text_content()==''
                for index in [1,2,3]:
                    assert mixed.locator('#textHeatingCircuitName'+str(index)).text_content()=='HK'+str(index)
                    assert str(25+index*5) in mixed.locator('#textMixerActual'+str(index)).text_content()
                    assert str(15+index*5) in mixed.locator('#textMixerTarget'+str(index)).text_content()
                    assert mixed.locator('#gMixerValveSymbol'+str(index)).get_attribute('data-state')==['opening','stopped','closing'][index-1]
                assert mixed.locator('[id^="textMixerState"]').count()==0
                assert mixed.locator('#gMixerValveSymbol1 circle[stroke="#ffc51b"]').count()==0
                assert mixed.locator('#gMixerValveSymbol1 text').last.text_content()=='↑'
                assert mixed.locator('.wp-valve-recent').count()==0
                assert '30' in mixed.locator('#textSupplyTemperatureHeating').text_content()
                assert '50' in mixed.locator('#textWPSupplyTemperature').text_content()
                assert '35' in mixed.locator('#textSupplyTemperatureHeating2').text_content()
                assert '40' in mixed.locator('#textWPReturnTemperature').text_content()
                assert mixed.locator('[id^="textRefluxTemperatureHeating"]').count()==0
                assert mixed.locator('#gMixerReadings').count()==0
                assert mixed.locator('[id^="gHKMixer"]').count()==3
                for n in [1,2,3]:
                    clearance=mixed.evaluate("""n=>{
                        const reading=document.getElementById('gMixerReading'+n).getBoundingClientRect();
                        const valve=document.getElementById('gMixerValveSymbol'+n).getBoundingClientRect();
                        return {readingBottom:reading.bottom,valveTop:valve.top};
                    }""",n)
                    assert clearance['readingBottom']<clearance['valveTop'],clearance

                assert mixed.locator('[id^="pathHKMixingReturn"]').count()==3
                assert mixed.locator('#textComputedCOP').text_content()=='4.25'
                assert mixed.locator('#textComputedJAZ').text_content()=='3.75'
                fonts=mixed.evaluate("()=>['textComputedCOP','textComputedJAZ','textPowerValue','textInputPower'].map(id=>getComputedStyle(document.querySelector('#'+id)).fontSize)")
                assert len(set(fonts))==1,fonts
                assert mixed.locator('#textComputedCOP').get_attribute('textLength') is None
                assert mixed.locator('#textComputedJAZ').get_attribute('textLength') is None
                label_fonts=mixed.evaluate("()=>['textLabelCOP','textLabelJAZ','textHeatingPowerLabel'].map(id=>getComputedStyle(document.querySelector('#'+id)).fontSize)")
                assert len(set(label_fonts))==1,label_fonts
                geometry=mixed.evaluate("""()=>{
                    const box=(id)=>{const b=document.querySelector('#'+id).getBoundingClientRect();return {x:b.x,y:b.y,right:b.right,bottom:b.bottom};};
                    return Object.fromEntries(['gMixerValveReadings','gHeatingHeaders','heatPumpPanel','switchValve','gHPUnitCabinet','gEfficiency','gRefrigerantCircuit','info'].map(id=>[id,box(id)]));
                }""")
                assert mixed.locator('#gMixerValveSymbol1').bounding_box()['x']>geometry['info']['right'],geometry
                assert geometry['gMixerValveReadings']['x']>geometry['gHeatingHeaders']['right'],geometry
                assert geometry['switchValve']['x']>geometry['heatPumpPanel']['x'],geometry
                assert geometry['switchValve']['right']<geometry['heatPumpPanel']['right'],geometry
                assert geometry['gEfficiency']['y']<geometry['info']['bottom'],geometry
                assert mixed.locator('#info #gEfficiency').count()==1
                assert mixed.locator('#gPrimaryMixerValve').count()==0
                for n in [1,2,3]:
                    assert mixed.locator('#gHKMixer'+str(n)+' #gMixerValveSymbol'+str(n)).count()==1
                original=mixed.evaluate("""()=>{
                    const script=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                    return JSON.parse(script.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                }""")
                renamed=json.loads(json.dumps(original))
                renamed['config'].update(heatingCircuitName1='Fußboden Erdgeschoss',heatingCircuitName2='Heizkörper Obergeschoss',heatingCircuitName3='Sehr langer Name für den dritten Heizkreis im Dachgeschoss')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',renamed)
                assert mixed.locator('#textHeatingCircuitName1').text_content().endswith('…')
                assert mixed.locator('#gHeatingCircuitName1 title').text_content()=='Fußboden Erdgeschoss'
                assert mixed.locator('#gHeatingCircuitName2 title').text_content()=='Heizkörper Obergeschoss'
                assert mixed.locator('#textHeatingCircuitName3').get_attribute('textLength') is None
                assert mixed.locator('#textHeatingCircuitName3').text_content().endswith('…')
                # Buffer precedes all mixer branches; removing it retains connected pipes.
                for has_buffer in [True,False]:
                    layout=json.loads(json.dumps(original))
                    layout['config']['tankHP']=has_buffer
                    mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',layout)
                    assert mixed.locator('#gBuffer').evaluate('e=>getComputedStyle(e).display')==('inline' if has_buffer else 'none')
                    assert mixed.locator('#gNoBuffer').evaluate('e=>getComputedStyle(e).display')==('none' if has_buffer else 'inline')
                    for n in [1,2,3]:
                        assert mixed.locator('#gHK'+str(n)+' > path.hot').get_attribute('d').endswith('H1260')
                        assert mixed.evaluate("n=>{const p=document.querySelector('#gHK'+n+' > path.cold'),h=document.querySelector('#gHeatingHeaders > path.cold');return Math.abs(p.getPointAtLength(p.getTotalLength()).x-h.getPointAtLength(0).x)<.1;}",n)
                        assert mixed.locator('#pathHKMixingReturn'+str(n)).get_attribute('d').endswith('V'+str(263+(n-1)*120))
                    placement=mixed.evaluate("""()=>{
                        const box=id=>{const b=document.querySelector('#'+id).getBoundingClientRect();return {x:b.x,y:b.y,right:b.right,bottom:b.bottom};};
                        return Object.fromEntries(['info','gBufferTankBody','gMixerValveSymbol1','gHKPumpIcon1','gHK1','heatPumpPanel'].map(id=>[id,box(id)]));
                    }""")
                    if has_buffer:
                        assert placement['gBufferTankBody']['x']>placement['info']['right'],placement
                        assert placement['gBufferTankBody']['right']<placement['gMixerValveSymbol1']['x'],placement
                        assert placement['gBufferTankBody']['bottom']<placement['heatPumpPanel']['y'],placement
                    assert placement['gMixerValveSymbol1']['right']<placement['gHKPumpIcon1']['x'],placement
                    screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                    if screenshot_dir: mixed.screenshot(path=str(Path(screenshot_dir)/f'mixed-buffer-{has_buffer}-{width}.png'))
                # Without buffer and solar, the circuit moves left and the canvas shrink together.
                slim=json.loads(json.dumps(original))
                slim['config'].update(tankHP=False,thermalSolarAvailable=False)
                slim['config']['heatingFlow']='testFlowPosition'
                slim['data']['testFlowPosition']={'value':612,'formatted':'612 l/h'}
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',slim)
                assert mixed.locator('#textHeatingFlow').get_attribute('y')=='580.5'
                assert mixed.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.width')<1570
                slim_x=mixed.locator('#gHK1').bounding_box()['x']
                full=json.loads(json.dumps(slim));full['config']['tankHP']=True
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',full)
                assert mixed.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.width')==1570
                # SVG coordinates, independent of the changed viewport scale.
                assert mixed.locator('#gHeating').get_attribute('transform')=='translate(0 0)'
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',slim)
                assert float(mixed.locator('#gHeating').get_attribute('transform').split('(')[1].split()[0])<0
                screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                if screenshot_dir: mixed.screenshot(path=str(Path(screenshot_dir)/f'no-buffer-no-solar-{width}.png'))
                single_flow=json.loads(json.dumps(slim))
                single_flow['config'].update(heatingCircuitType2='off',heatingCircuitType3='off')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',single_flow)
                assert mixed.locator('#textHeatingFlow').get_attribute('x')=='680'
                assert mixed.locator('#textHeatingFlow').get_attribute('y')=='580.5'
                if screenshot_dir: mixed.screenshot(path=str(Path(screenshot_dir)/f'flow-upper-supply-{width}.png'))
                # Circuit temperatures remain MK measurements even without a drawn mixer valve.
                direct=json.loads(json.dumps(original))
                direct['config'].update(mixers=[],tankHP=True)
                direct['config']['returnTemperatureSetpoint']='testDirectReturnTarget'
                direct['data']['testDirectReturnTarget']={'value':27,'formatted':'27.0 °C'}
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',direct)
                assert mixed.locator('[id^="gMixerValveSymbol"]').count()==0
                assert mixed.locator('#gNoBuffer > path.hot').get_attribute('d').startswith('M825 567.5V245H')
                assert 'H790V762.5' in mixed.locator('#gNoBuffer > path.cold').get_attribute('d')
                assert 'V550' not in mixed.locator('#gNoBuffer > path.hot').get_attribute('d')
                clearances=mixed.evaluate("""()=>{
                    const branch=document.querySelector('#gHK1 > path.hot').getBoundingClientRect();
                    const pump=document.querySelector('#gHKPumpIcon1').getBoundingClientRect();
                    return {start:branch.x,end:branch.right,pumpLeft:pump.x,pumpRight:pump.right};
                }""")
                assert clearances['start']<clearances['pumpLeft']<clearances['pumpRight']<clearances['end'],clearances


                for n in [1,2,3]:
                    selector='#textSupplyTemperatureHeating'+('' if n==1 else str(n))
                    assert str(25+n*5) in mixed.locator(selector).text_content()
                    assert mixed.locator(selector).text_content().startswith('VL: ')
                assert mixed.locator('#textWPReturnTarget').text_content()=='RL Soll: 27.0 °C'

                missing=json.loads(json.dumps(original))
                missing['data'].pop('mixerActual1')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',missing)
                assert mixed.locator('#textMixerActual1').text_content()=='MK1: —'
                assert mixed.locator('#textSupplyTemperatureHeating').is_hidden()
                assert '50' in mixed.locator('#textWPSupplyTemperature').text_content()
                # DHW charging changes WP temperatures, never the measured mixed circuit supplies.
                hotwater=json.loads(json.dumps(original))
                for key,value in [('supplyTemperature',60),('heatPumpReturnTemperature',55)]:
                    hotwater['data'][hotwater['config'][key]].update(value=value,formatted=str(value)+'.0 °C')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',hotwater)
                assert mixed.locator('#textWPSupplyTemperature').text_content()=='VL: 60.0 °C'
                assert mixed.locator('#textWPReturnTemperature').text_content()=='RL: 55.0 °C'
                assert '30' in mixed.locator('#textSupplyTemperatureHeating').text_content()
                # Missing relays are unknown; both inactive relays mean neither direction is driven.
                updated=json.loads(json.dumps(original))
                updated['data'].pop('mixerOpen1')
                updated['data']['computedCOP'].update(value=0,formatted='0.00')
                updated['data']['computedJAZ'].update(value=4.1,formatted='4.10')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gMixerValveSymbol1').get_attribute('data-state')=='unknown'
                assert mixed.locator('#textComputedCOP').text_content()=='0.00'
                assert mixed.locator('#textComputedJAZ').text_content()=='4.10'
                updated['data']['mixerOpen1']={'value':True,'binary':True}
                updated['data']['mixerClose1']={'value':True,'binary':True}
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gMixerValveSymbol1').get_attribute('data-state')=='conflict'
                assert 'Auf und Zu aktiv' in mixed.locator('#gMixerValveSymbol1').get_attribute('aria-label')
                # Output changes blink for at most a minute, including a stop command.
                mixed.clock.install()
                changed=mixed.evaluate('Date.now()/1000')
                updated['data']['mixerOpen1'].update(value=False,changedAt=changed)
                updated['data']['mixerClose1'].update(value=True,changedAt=changed)
                updated['config']['wwHeatingValve']='testValve'
                updated['data']['testValve']={'value':True,'binary':True,'changedAt':changed}
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gMixerValveSymbol1 .wp-valve-recent').count()==1
                assert mixed.locator('#switchValveFlow.wp-valve-recent').count()==1
                assert 'Ansteuerung Zu' in mixed.locator('#gMixerValveSymbol1').get_attribute('aria-label')
                assert mixed.locator('#switchValveFlow').get_attribute('data-target')=='hotwater'
                assert mixed.locator('#switchValveFlow').get_attribute('d')=='M-16 0H16'
                mixed.clock.fast_forward(60100)
                assert mixed.locator('.wp-valve-recent').count()==0
                assert mixed.locator('#gMixerValveSymbol1').get_attribute('data-state')=='closing'
                updated['data']['mixerClose1'].update(value=False,changedAt=mixed.evaluate('Date.now()/1000'))
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gMixerValveSymbol1').get_attribute('data-state')=='stopped'
                assert mixed.locator('#gMixerValveSymbol1 .wp-valve-recent').count()==1
                mixed.clock.fast_forward(60100)
                assert mixed.locator('.wp-valve-recent').count()==0
                # A sole third heating place still has a connected distributor.
                updated['config'].update(heatingCircuitType1='off',heatingCircuitType2='off')
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gHeatingHeaders').evaluate('e=>getComputedStyle(e).display')!='none'
                assert mixed.locator('#gNoBuffer > path.cold').get_attribute('d').endswith('V762.5')
                assert mixed.locator('#gNoBuffer > path.hot').get_attribute('d').startswith('M825 567.5V485H')
                assert mixed.locator('#gHKMixer1 > g,#gHKMixer2 > g').count()==0
                updated['config']['tankHP']=True
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',updated)
                assert mixed.locator('#gBuffer > path.cold').first.get_attribute('d')=='M720 380H705V440H790V762.5'
                assert mixed.locator('#gBuffer > path.cold').last.get_attribute('d')=='M965 380H890'
                mixed.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',original)
                screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                if screenshot_dir: mixed.screenshot(path=str(Path(screenshot_dir)/f'mixed-{width}.png'))
                assert not errors,errors
                mixed.close()

            # Selected room count controls the layout and scales a sole reading up.
            for width in [1200,390]:
                font_sizes=[]
                for count in [1,2,3]:
                    rooms=browser.new_page(viewport={'width':width,'height':850})
                    errors=[]
                    rooms.on('pageerror',lambda e: errors.append(str(e)))
                    rooms.goto(f'http://127.0.0.1:{server.server_port}/rooms-{count}.html')
                    assert rooms.locator('#gRoomTemperatures > g').count()==count
                    assert rooms.locator('#indoorDefaultIcon').evaluate('e=>getComputedStyle(e).display')=='none'
                    assert rooms.locator('#textRoomTemperature1').text_content()=='21.0 °C'
                    assert rooms.locator('#gRoomTemperature1 i').get_attribute('class')=='fa-light fa-temperature-half'
                    assert rooms.locator('#textIndoorTemperatureLabel').text_content()==''
                    if count==1: assert 'Wohnzimmer' in rooms.locator('#gRoomTemperature1').text_content()
                    if count>=2:
                        assert 'Bad' in rooms.locator('#gRoomTemperature2').text_content()
                        assert rooms.locator('#gRoomTemperature2 i').get_attribute('class')=='fa-light fa-bath'
                    if count==3: assert rooms.locator('#gRoomTemperature3 > path').count()==1
                    font_sizes.append(rooms.locator('#textRoomTemperature1').evaluate('e=>parseFloat(getComputedStyle(e).fontSize)'))
                    # Room rows stay in the indoor half of the information card.
                    boxes=rooms.locator('#gRoomTemperatures > g').evaluate_all('(es)=>es.map(e=>{const b=e.getBoundingClientRect();return {y:b.y,bottom:b.bottom,right:b.right};})')
                    divider=rooms.locator('#info > line').first.bounding_box()
                    for box in boxes: assert box['right']<divider['x'],(boxes,divider)
                    for a,b in zip(boxes,boxes[1:]): assert a['bottom']<=b['y']+1,boxes
                    rooms.evaluate('''()=>{
                        const script=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                        const p=JSON.parse(script.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                        p.config.roomTemperatures.forEach((room,index)=>room.display=['name','icon','both'][index]);
                        window.handleMessage({type:'update',config:p.config,data:p.data,controls:p.controls});
                    }''')
                    assert rooms.locator('#textRoomName1').count()==1
                    assert rooms.locator('#gRoomTemperature1 foreignObject,#gRoomTemperature1 > path').count()==0
                    assert rooms.locator('#textRoomTemperature1').text_content()=='21.0 °C'
                    if count>=2:
                        assert rooms.locator('#textRoomName2').count()==0
                        assert rooms.locator('#gRoomTemperature2 foreignObject').count()==1
                    if count==3:
                        assert rooms.locator('#textRoomName3').count()==1
                        assert rooms.locator('#gRoomTemperature3 > path').count()==1
                    screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                    if screenshot_dir: rooms.screenshot(path=str(Path(screenshot_dir)/f'rooms-{count}-{width}.png'))
                    assert not errors,errors
                    rooms.close()
                assert font_sizes[0]>font_sizes[1]>=font_sizes[2],font_sizes
            print(json.dumps({'heating_places':'PASS','mixer_telemetry':'PASS','indoor_1_2_3':'PASS','cop_jaz':'PASS'}))

            # Actual frequency controls movement; a positive setpoint alone never does.
            for width in [1200,390]:
                frequency=browser.new_page(viewport={'width':width,'height':850})
                frequency.goto(f'http://127.0.0.1:{server.server_port}/compressor-frequency.html')
                assert frequency.locator('#wp-error').text_content()==''
                assert frequency.locator('#textCompressorSpeedValue').text_content()=='Ist 55 rpm'
                assert frequency.locator('#textCompressorFrequencyTarget').text_content()=='Soll 60 rpm'
                assert frequency.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='symcon-modern-rotate'
                assert frequency.locator('#textHeatPumpCycle').text_content()=='Läuft seit 2h 3m'
                assert frequency.locator('#gHeatPumpCycle > text').count()==1
                assert frequency.locator('#textHeatPumpCycle').evaluate('e=>e.getBBox().width')<=175.5
                update=json.loads(Path(artifacts,'compressor-frequency-update.json').read_text())
                frequency.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert frequency.locator('#textCompressorSpeedValue').text_content()=='Ist 0 rpm'
                assert frequency.locator('#textCompressorFrequencyTarget').text_content()=='Soll 60 rpm'
                assert frequency.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='none'
                assert frequency.locator('#textHeatPumpCycle').text_content()=='Steht seit 1h 14m'
                assert frequency.locator('#gHeatPumpCycle > text').count()==1
                update['data'].pop(update['config']['compressorStandstill'])
                frequency.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert frequency.locator('#textHeatPumpCycle').text_content()=='Steht seit —'
                update['data'].pop(update['config']['compressorValue'])
                update['data'].pop(update['config']['compressorRunning'])
                frequency.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert frequency.locator('#textHeatPumpCycle').text_content()=='Lauf-/Standzeit —'
                frequency.close()

            # Each meter stays independent when another heater is switched.
            for width in [1200,390]:
                heaters=browser.new_page(viewport={'width':width,'height':850})
                heaters.goto(f'http://127.0.0.1:{server.server_port}/heater-associations.html')
                assert heaters.locator('#wp-error').text_content()==''
                for target in ['#textHeaterButtonState','#textBoilerHeaterPower']:
                    assert heaters.locator(target).text_content()=='1.36 kW'
                heaters.evaluate('() => {window.testActions=[];window.requestAction=(ident,value)=>window.testActions.push({ident,payload:JSON.parse(value)});}')
                heaters.locator('#gHeaterControlButton').dispatch_event('click')
                titles=heaters.locator('#wp-mode-menu .wp-mode-title').all_text_contents()
                assert titles[1:]==['Heizstab 1: Automatisch · 0.00 kW','Heizstab 2: Automatisch · 0.00 kW','Heizstab 3: Aktiv · 1.36 kW'],titles
                heaters.locator('#wp-mode-menu [data-heater="1"][data-value="2"]').click()
                assert heaters.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':1,'value':2}}
                assert heaters.locator('#textHeaterButtonState').text_content()=='1.36 kW'
                signed=json.loads(Path(artifacts,'heater-signed-update.json').read_text())
                heaters.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',signed)
                for target in ['#textHeaterButtonState','#textBoilerHeaterPower']:
                    assert heaters.locator(target).text_content()=='1.36 kW'
                heaters.locator('#gHeaterControlButton').dispatch_event('click')
                assert '—' not in heaters.locator('#wp-mode-menu').text_content()
                assert '≥' not in heaters.locator('#wp-card').text_content()
                update=json.loads(Path(artifacts,'heater-associations-update.json').read_text())
                heaters.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                for target in ['#textHeaterButtonState','#textBoilerHeaterPower']:
                    assert heaters.locator(target).text_content()=='0.00 kW'
                assert heaters.locator('#pathBoilerHeatBolt').get_attribute('class')==''
                heaters.close()

            # Confirmed service measurements stay independent of object creation and unavailable fields.
            for width in [1200,390]:
                service=browser.new_page(viewport={'width':width,'height':900})
                service.goto(f'http://127.0.0.1:{server.server_port}/service-values.html')
                assert service.locator('#textHotGasTemperature').text_content()=='Heißgas: 86.5 °C'
                assert service.locator('#textFanSpeed').text_content()=='Lüfter: 489 rpm'
                assert service.locator('#textTimeUntilDefrost').text_content()=='Abtauen in 0h 33m'
                time_gap=service.evaluate("""()=>{
                    const a=document.getElementById('textHeatPumpCycle').getBoundingClientRect(),b=document.getElementById('textTimeUntilDefrost').getBoundingClientRect();
                    return b.y-a.bottom;
                }""")
                assert time_gap>0,time_gap

                zero=json.loads(Path(artifacts,'service-values-zero.json').read_text())
                service.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',zero)
                assert service.locator('#textHotGasTemperature').text_content()=='Heißgas: 0.0 °C'
                assert service.locator('#textFanSpeed').text_content()=='Lüfter: 0 rpm'
                assert service.locator('#textTimeUntilDefrost').text_content()=='Abtauen in 0h 0m'
                missing=json.loads(Path(artifacts,'service-values-missing.json').read_text())
                service.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',missing)
                assert service.locator('#textHotGasTemperature,#textFanSpeed,#textTimeUntilDefrost').count()==0
                service.close()

            # SHI has its own states, current measurements and shared numeric controls.
            # All native config switches drive the bar, including room and pool controls.
            for width in [1200,390]:
                bar=browser.new_page(viewport={'width':width,'height':850})
                bar.goto(f'http://127.0.0.1:{server.server_port}/all-controls.html')
                bar.wait_for_timeout(100)
                all_controls=bar.evaluate('''()=>{
                    const s=[...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init('));
                    return JSON.parse(s.textContent.trim().slice('SymconHeatPump.init('.length,-2));
                }''')
                selectors={'hotwater':'#gHPStatusWW','heating':'#gHPStatusHeating','cooling':'#gHPStatusCooling',
                    'pool':'#gHPStatusPool','warmWaterSetpoint':'#gSymconWarmWaterSetpoint',
                    'heatingTemperatureCorrection':'#gSymconHeatingCorrection','roomTemperatureSetpoint':'#gSymconRoomTemperatureSetpoint'}
                variants=json.loads(Path(artifacts,'controls-variants.json').read_text())
                for compact in [False,True]:
                    cfg={**all_controls['config'],'thermalSolarAvailable':not compact,'tankHP':not compact}
                    for controls in [all_controls['controls'],*variants]:
                        bar.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,controls:p.controls})',{'config':cfg,'controls':controls})
                        for function,selector in selectors.items():
                            assert bar.locator(selector).is_visible()==controls[function]['configured'],(width,function,controls)
                        boxes=bar.evaluate('''(selectors)=>{
                            const svg=document.querySelector('#wp-card svg').getBoundingClientRect();
                            return [...selectors,'#gHeaterControlButton','#gSettings'].map(s=>document.querySelector(s))
                                .filter(e=>getComputedStyle(e).display!=='none').map(e=>{
                                    const b=e.querySelector('rect').getBoundingClientRect();
                                    return {x:b.x,right:b.right,y:b.y,bottom:b.bottom,limit:svg.right};
                                });
                        }''',list(selectors.values()))
                        for left,right in zip(boxes,boxes[1:]):
                            assert left['right']<right['x'] and abs(left['y']-right['y'])<1,boxes
                        assert boxes[-1]['right']<=boxes[-1]['limit']+1,boxes
                bar.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,controls:p.controls})',all_controls)
                bar.evaluate('''()=>{
                    window.testActions=[];
                    window.requestAction=(ident,value)=>window.testActions.push({ident,payload:JSON.parse(value)});
                }''')
                bar.locator('#gSymconRoomTemperatureSetpoint').dispatch_event('click')
                field=bar.locator('#wp-mode-menu input')
                assert field.get_attribute('min')=='0' and field.get_attribute('max')=='35'
                field.fill('21.5')
                bar.get_by_role('button',name='Übernehmen',exact=True).dispatch_event('click')
                assert bar.evaluate('window.testActions.at(-1)')=={'ident':'SetNumericControl','payload':{'function':'roomTemperatureSetpoint','value':21.5}}
                bar.locator('#gHPStatusPool').dispatch_event('click')
                bar.locator('#wp-mode-menu [data-value="2"]').dispatch_event('click')
                assert bar.evaluate('window.testActions.at(-1)')=={'ident':'SetControlMode','payload':{'function':'pool','value':2}}
                bar.close()
            print(json.dumps({'native_control_switches':'PASS','room_and_pool_actions':'PASS','dynamic_button_layout':'PASS'}))

            for width in [1200,390]:
                shi=browser.new_page(viewport={'width':width,'height':850})
                shi.goto(f'http://127.0.0.1:{server.server_port}/shi.html')
                assert shi.locator('#wp-error').text_content()==''
                assert shi.locator('#textHPStatus').text_content()=='Heizen'
                assert '31.5' in shi.locator('#textMixerActual1').text_content()
                assert shi.locator('#textInputPower').text_content()=='1.20 kW'
                assert shi.locator('#gSymconWarmWaterSetpoint').is_visible()
                assert shi.locator('#gSymconHeatingCorrection').is_visible()
                assert not shi.locator('#gHPStatusWW').is_visible()
                assert shi.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='symcon-modern-rotate'
                assert shi.locator('#textHeatPumpCycle').text_content()=='Läuft seit —'
                update=json.loads(Path(artifacts,'shi-update.json').read_text())
                shi.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert shi.locator('#textHPStatus').text_content()=='Warmwasser'
                assert shi.locator('#switchValveFlow').get_attribute('data-target')=='hotwater'
                assert shi.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='none'
                assert shi.locator('#textHeatPumpCycle').text_content()=='Steht seit —'
                shi.close()

            # A mixed snapshot displays current Modbus data alongside live Java supplements.
            for width in [1200,390]:
                hybrid=browser.new_page(viewport={'width':width,'height':850})
                hybrid.goto(f'http://127.0.0.1:{server.server_port}/hybrid.html')
                assert hybrid.locator('#wp-error').text_content()==''
                assert hybrid.locator('#textHeatingFlow').text_content()=='612 l/h'
                assert hybrid.locator('#textHeatingFlow').get_attribute('x')=='680'
                assert hybrid.locator('#textHeatingFlow').get_attribute('y')=='580.5'
                assert hybrid.locator('#textCompressorSpeedValue').text_content()=='Ist 55 rpm'
                assert hybrid.locator('#textCondenserTemperature').text_content()=='8.2 °C'
                assert hybrid.locator('#textHeatPumpCycle').text_content()=='Läuft seit 2h 3m'
                update=json.loads(Path(artifacts,'hybrid-update.json').read_text())
                hybrid.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert hybrid.locator('#textHeatingFlow').text_content()=='600 l/h'
                assert hybrid.locator('#textHeatPumpCycle').text_content()=='Steht seit 1h 14m'
                assert hybrid.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='none'
                update=json.loads(Path(artifacts,'hybrid-missing.json').read_text())
                hybrid.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert hybrid.locator('#textHeatingFlow').count()==0
                assert hybrid.locator('#textCondenserTemperature').text_content()==''
                assert hybrid.locator('#textHeatPumpCycle').text_content()=='Läuft seit —'
                assert hybrid.locator('#textHPStatus').text_content()=='Heizen'
                hybrid.close()

            # Native measurements render and update with no measurement objects at all.
            internal_update=json.loads(Path(artifacts,'internal-only-update.json').read_text())
            for width in [1200,390]:
                internal=browser.new_page(viewport={'width':width,'height':850})
                internal.goto(f'http://127.0.0.1:{server.server_port}/internal-only.html')
                assert '24.9' in internal.locator('#textMixerActual1').text_content()
                assert internal.locator('#textHPStatus').text_content()=='Keine Anforderung'
                assert internal.locator('#textInputPower').text_content()=='1.50 kW'
                assert internal.locator('#textHeatingFlow').text_content()=='607 l/h'
                assert '48.5' in internal.locator('#textDHWIst').text_content()
                assert internal.locator('#gMixerValveSymbol1').get_attribute('data-state')=='opening'
                assert internal.locator('#textHeatingPumpSpeed').count()==0
                internal.evaluate('(p)=>window.handleMessage(p)',internal_update)
                assert '25.1' in internal.locator('#textMixerActual1').text_content()
                internal.close()

            # Optional circulation and actual storage/solar signals use the same internal cache.
            for width in [1200,390]:
                comp=browser.new_page(viewport={'width':width,'height':850})
                comp.goto(f'http://127.0.0.1:{server.server_port}/components.html')
                assert comp.locator('#textBufferReturnTemperature').count()==0
                assert '42.0' in comp.locator('#textSolarStorageTemperature').text_content()
                assert comp.locator('#textThermalSolarPumpSpeed').text_content()=='Ein'
                assert '31.7' in comp.locator('#textHeatingPump1').text_content()
                assert comp.locator('#textHeatingPumpSpeed').count()==0
                assert comp.locator('#gCirculatingPumpVisual').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#pathHKMixingReturn1').get_attribute('d')=='M1045 295V263'
                original=comp.evaluate("""()=>JSON.parse([...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init(')).textContent.trim().slice('SymconHeatPump.init('.length,-2))""")
                changed=json.loads(json.dumps(original))
                changed['config'].update(circulation=False,solarTarget='buffer',tankWW=False)
                comp.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert comp.locator('#gCirculatingPumpVisual').evaluate('e=>getComputedStyle(e).display')=='none'
                assert comp.locator('#gSolar').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gSolar > path.sol').get_attribute('d').startswith('M884 300')
                screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                if screenshot_dir: comp.screenshot(path=str(Path(screenshot_dir)/f'solar-buffer-{width}.png'))
                changed['config'].update(tankHP=False,tankWW=False,solarTarget='buffer')
                comp.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert comp.locator('#gSolar').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gDHW').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gSolar > path.sol').get_attribute('d').startswith('M1088 744.5')
                changed['config']['thermalSolarAvailable']=False
                comp.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert comp.locator('#gSolar').evaluate('e=>getComputedStyle(e).display')=='none'
                assert comp.locator('#gDHW').evaluate('e=>getComputedStyle(e).display')=='none'
                comp.close()

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

            # Named EEV reads use one explicit diagnostic session; no control command or SDK action.
            for width in [1200,390]:
                diagnostic=browser.new_page(viewport={'width':width,'height':850})
                diagnostic.clock.install()
                peer=LuxtronikFixture()
                diagnostic.route_web_socket(re.compile(r'^ws://127\.0\.0\.1:8214/$'),peer.connect)
                diagnostic.goto(f'http://127.0.0.1:{server.server_port}/configured.html')
                # Host themes can provide white text together with a transparent card background.
                diagnostic.evaluate('''()=>{
                    document.documentElement.style.setProperty('--content-color','#ffffff');
                    document.documentElement.style.setProperty('--card-background-color','transparent');
                    document.body.style.background='#ffffff';
                }''')
                diagnostic.evaluate('(url)=>window.handleMessage({type:"update",config:{luxtronikWebInterfaceURL:url}})',web_url)
                diagnostic.locator('#gSettings').dispatch_event('click')
                diagnostic.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                assert diagnostic.locator('#wp-eev-test').is_visible()
                assert diagnostic.locator('#wp-eev-test').text_content()=='EEV-Diagnose starten'
                assert not diagnostic.locator('#wp-eev-diagnostic').evaluate('e=>e.open')
                for scheme in ['light','dark']:
                    diagnostic.emulate_media(color_scheme=scheme)
                    colors=diagnostic.locator('#wp-luxtronik-dialog').evaluate('''e=>({
                        foreground:getComputedStyle(e).color,background:getComputedStyle(e).backgroundColor,
                        heading:getComputedStyle(document.getElementById('wp-luxtronik-title')).color,
                        summary:getComputedStyle(document.querySelector('#wp-eev-diagnostic summary')).color})''')
                    assert colors['foreground']!=colors['background'],colors
                    assert colors['background'] not in ['transparent','rgba(0, 0, 0, 0)'],colors
                    assert colors['heading']==colors['foreground'] and colors['summary']==colors['foreground'],colors
                diagnostic.emulate_media(color_scheme='light')
                diagnostic.evaluate('()=>{window.testDiagnosticActions=[];window.requestAction=(...args)=>window.testDiagnosticActions.push(args);}')

                def read_eev():
                    diagnostic.locator('#wp-eev-test').click()
                    assert diagnostic.locator('#wp-eev-diagnostic').evaluate('e=>e.open')
                    diagnostic.wait_for_function('!document.querySelector("#wp-eev-copy").disabled')
                    report=json.loads(diagnostic.locator('#wp-eev-result').input_value())
                    commands=peer.connections[-1]['commands']
                    assert commands[:2]==['LOGIN;999999','REFRESH'],commands
                    assert all(c=='REFRESH' or c.startswith(('LOGIN;','GET;')) for c in commands),commands
                    assert diagnostic.locator('#wp-luxtronik-controls').evaluate('e=>e.disabled')
                    assert diagnostic.evaluate('window.testDiagnosticActions')==[],diagnostic.evaluate('window.testDiagnosticActions')
                    assert '999999' not in json.dumps(report)
                    return report,commands

                report,commands=read_eev()
                assert report['ok'] and report['eev']['heating']['value']==34.8 and report['eev']['cooling']['value']==100
                assert report['capturedAt'] and any(step.get('reply')=='content' for step in report['steps'])
                assert report['eev']['heating']['id']=='eev-heat-2' and report['missing']==[]
                assert commands==['LOGIN;999999','REFRESH','GET;info','GET;outputs-2'],commands
                assert 'EEV Heizen' in report['outputXml']
                peer.eev_values=('0,0\u00a0%','0.0 %')
                report,_=read_eev()
                assert report['eev']['heating']['value']==0 and report['eev']['cooling']['value']==0
                peer.eev_values=('101 %','—')
                report,_=read_eev()
                assert report['eev']['heating']['value'] is None and report['eev']['cooling']['value'] is None
                assert report['missing']==['heating','cooling']
                peer.inline_outputs=True
                report,commands=read_eev()
                assert report['ok'] and report['eev']['heating']['value']==0 and report['eev']['cooling'] is None
                assert commands==['LOGIN;999999','REFRESH'],commands
                peer.inline_outputs=False
                for xml,error in [
                    ('<Content><item>','XML'),
                    ('<!DOCTYPE Content [<!ENTITY x SYSTEM "file:///etc/passwd">]><Content/>','XML'),
                    ('<Content id="different"/>','anderen Menü'),
                    ('<Content><item><name>EEV Heizen</name><value>1 %</value></item><item><name>EEV Heizen</name><value>2 %</value></item></Content>','mehrfach'),
                ]:
                    peer.output_xml=xml
                    report,_=read_eev()
                    assert not report['ok'] and error in report['error'],report
                peer.output_xml=None
                peer.information_xml='<Content><item id="bad;command"><name>Ausgänge</name></item></Content>'
                report,commands=read_eev()
                assert not report['ok'] and 'Menükennung' in report['error'] and len(commands)==3,report
                peer.information_xml='<Content><name>Informationen</name></Content>'
                report,_=read_eev()
                assert not report['ok'] and 'Ausgänge fehlt' in report['error'],report
                peer.information_xml=None
                peer.silent=True
                diagnostic.locator('#wp-eev-test').click()
                diagnostic.clock.fast_forward(12001)
                diagnostic.wait_for_function('!document.querySelector("#wp-eev-copy").disabled')
                report=json.loads(diagnostic.locator('#wp-eev-result').input_value())
                assert not report['ok'] and '12 Sekunden' in report['error']
                diagnostic.locator('#wp-eev-test').click()
                diagnostic.locator('#wp-luxtronik-close').click()
                diagnostic.wait_for_timeout(50)
                assert peer.connections[-1]['closed']
                assert not any(c.startswith(('MOVE;','SET;','CHANGE;')) for c in peer.commands)
                diagnostic.close()
            print(json.dumps({'eev_read_only':'PASS','eev_zero_missing':'PASS','eev_xml_and_timeout':'PASS'}))

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
                content_type='application/javascript' if route.request.url.endswith('/icons.js') else 'text/html',
                body='' if route.request.url.endswith('/icons.js') else Path(artifacts,'configured.html').read_text()))
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
