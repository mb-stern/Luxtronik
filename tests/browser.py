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


def assert_solar_pipe_clearance(path, minimum=8):
    """Check all segments, including collector-side horizontal connections."""
    tokens=re.findall(r'[MHV]|-?\d+(?:\.\d+)?',path)
    paths=[];i=0;x=y=0
    while i<len(tokens):
        command=tokens[i];i+=1
        if command=='M':
            x,y=map(float,tokens[i:i+2]);i+=2;paths.append([])
        else:
            assert command in ('H','V'),command
            value=float(tokens[i]);i+=1
            end=(value,y) if command=='H' else (x,value)
            paths[-1].append(((x,y),end));x,y=end
    assert len(paths)==2,paths
    for a in paths[0]:
        for b in paths[1]:
            dx=max(min(p[0] for p in a)-max(p[0] for p in b),min(p[0] for p in b)-max(p[0] for p in a),0)
            dy=max(min(p[1] for p in a)-max(p[1] for p in b),min(p[1] for p in b)-max(p[1] for p in a),0)
            assert dx*dx+dy*dy>=minimum*minimum,(a,b,'Solar pipes touch or cross')


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
        self.additional_information = []
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
                    extra=''.join(f'<item id="{item["id"]}"><name>{item["name"]}</name></item>' for item in self.additional_information)
                    route.send(self.information_xml if self.information_xml is not None else
                        f'<Content id="info"><name>Informationen</name><item id="outputs-{index}"><name>Ausgänge</name></item>{extra}</Content>')
                    return
                if message==f'GET;outputs-{index}':
                    route.send(self.output_xml if self.output_xml is not None else
                        f'<Content id="outputs-{index}"><name>Ausgänge</name><item id="hup-{index}"><name>HUP</name><value>19.5 %</value></item>'
                        f'<item id="eev-heat-{index}"><name>EEV Heizen</name><value>{self.eev_values[0]}</value></item>'
                        f'<item id="eev-cool-{index}"><name>EEV Kühlen</name><value>{self.eev_values[1]}</value></item></Content>')
                    return
                for item in self.additional_information:
                    if message=='GET;'+item['id']:
                        route.send(item['xml'])
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
    subprocess.run(["php", "-d", "extension=dom", str(repository / "tests/web-eev.php")],
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
                for hotwater,path in [(True,'M0 16V0H16'),(False,'M0 16V-16')]:
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
                assert page.locator('#textExpansionValveOpening').text_content()=='52.5 %'
                eev['data']['testCoolingMode']['value']=True
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',eev['data'])
                assert page.locator('#textExpansionValveOpening').text_content()=='100.0 %'
                eev['data']['testEEVCooling']['value']=1000
                page.evaluate('(data)=>window.handleMessage({type:"update",data})',eev['data'])
                assert page.locator('#textExpansionValveOpening').text_content()=='—'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',payload)
                assert page.locator('#gFooter').count()==0
                assert page.locator('#wp-card svg').evaluate('e=>e.viewBox.baseVal.height')==895
                # The narrow layout leaves enough room for straight pipes and the shared pump.
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
                web_payload['config']['luxtronikWebSocketPassword']='123456'
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
                assert luxtronik.commands[:3]==['LOGIN;123456','REFRESH','GET;remote-1'],luxtronik.commands
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
                assert page.locator('#wp-luxtronik-password').count()==0
                web_payload['config']['luxtronikWebSocketPassword']='bad;password'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',web_payload)
                page.get_by_role('button',name='Erneut verbinden',exact=True).click()
                page.wait_for_function('document.querySelector("#wp-luxtronik-help").textContent.includes("unzulässige")')
                assert 'LOGIN;bad;password' not in luxtronik.commands
                web_payload['config']['luxtronikWebSocketPassword']='123456'
                page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',web_payload)
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
                assert page.locator('#textHeatingFlow').get_attribute('x')=='710'
                assert page.locator('#textHeatingFlow').get_attribute('y')=='752'
                assert 'Ansteuerung' not in page.locator('#textHeatingPump1').text_content()
                small_labels=page.evaluate('''()=>{
                    const svg=document.querySelector('#wp-card svg');
                    const minimum=parseFloat(getComputedStyle(svg.querySelector('#textEvaporatorPressure')).fontSize);
                    return [...svg.querySelectorAll('text,tspan')].filter(e=>getComputedStyle(e).display!=='none' && parseFloat(getComputedStyle(e).fontSize)<minimum).map(e=>e.id||e.textContent);
                }''')
                assert small_labels==[],small_labels
                assert page.locator('#textEvaporatorPressure').evaluate('e=>getComputedStyle(e).fontSize')==('20px' if width<=600 else '18px')
                assert page.locator('#textCompressorLabel,#textExpansionValveLabel').evaluate_all('els=>els.every(e=>getComputedStyle(e).display==="none")')==(width<=600)
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
                        tank:b('gDHWTankBody').toJSON(),
                        tankBase:document.querySelector('#gDHWTankBody > ellipse:last-of-type').getBoundingClientRect().bottom,
                        hotY:point('#main > path.hot',true).y,coldY:point('#main > path.cold',false).y};
                }""")
                assert geometry['panel']['x']<geometry['values']['x']<geometry['values']['right']<geometry['panel']['right'],geometry
                assert geometry['panel']['y']<geometry['values']['y']<geometry['values']['bottom']<geometry['panel']['bottom'],geometry
                assert geometry['tank']['y']<geometry['hotY']<geometry['coldY']<geometry['tankBase'],geometry
                assert abs(geometry['panel']['bottom']-geometry['tankBase']-44*geometry['panel']['height']/410)<1,geometry
                assert page.locator('#gHeatPumpTemperatures text').count()==3
                align=page.evaluate("""()=>{
                    const n=document.getElementById('textHeatingCircuitName1'),v=document.getElementById('textSupplyTemperatureHeating');
                    const a=n.getBoundingClientRect(),b=v.getBoundingClientRect();
                    return {nameX:a.x,valueX:b.x,nameBottom:a.bottom,valueTop:b.top,nameFont:getComputedStyle(n).fontSize,valueFont:getComputedStyle(v).fontSize};
                }""")
                assert abs(align['nameX']-align['valueX'])<1 and align['nameBottom']<align['valueTop'],align
                assert align['nameFont']==align['valueFont'],align

                assert page.locator('#textWPSupplyTemperatureLabel,#textWPReturnTemperatureLabel,#textWPReturnTargetLabel').count()==0
                assert page.locator('#switchValve').get_attribute('transform')=='translate(700 580)'
                assert page.locator('#main > path.hot').get_attribute('d')=='M650 680H700V580'
                assert page.locator('#main > path.cold').get_attribute('d')=='M810 815H700V705H650'
                assert page.locator('#gDHW > path.hot').get_attribute('d')=='M701 585H900'
                assert float(page.locator('#textWPSupplyTemperature').get_attribute('y'))>580
                assert float(page.locator('#textWPReturnTemperature').get_attribute('y'))<815


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
                        assert page.locator('#wp-mode-menu').is_visible()
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
                assert page.locator('#wp-mode-menu').is_visible()
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':1,'value':True}}
                heater_controls=json.loads(json.dumps(payload['controls']))
                heater_controls['heaterSwitches'][0]['currentValue']=True
                heater_controls['heaterSwitches'][0]['enabled']=True
                heater_controls['heaterSwitches'][2]['configured']=False
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',heater_controls)
                assert page.locator('#wp-mode-menu [data-heater="1"][data-value="false"]').text_content()=='Aus'
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
                assert page.locator('#wp-mode-menu select[data-heater="2"]').input_value()=='1'
                page.locator('#wp-mode-menu select[data-heater="2"]').select_option(index=2)
                assert page.evaluate('window.testActions.at(-1)')=={'ident':'SetHeaterSwitch','payload':{'number':2,'value':30}}
                heater_controls['heaterSwitches'][1].update(currentValue=1,stateLabel='Automatik',options=[{'value':0,'name':'Aus','role':'off'},{'value':2,'name':'Ein','role':'on'}])
                page.evaluate('(controls)=>window.handleMessage({type:"update",controls})',heater_controls)
                page.locator('#gHeaterControlButton').dispatch_event('click')
                assert page.locator('#wp-mode-menu [data-heater="2"][data-value="1"]').count()==0
                assert page.locator('#wp-mode-menu [data-heater="2"][data-value="2"]').text_content()=='Ein'
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
                    sample['data'][sample['config']['wwHeatingValve']]['value']=False
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
                    return page.locator('#primaryPumpRotor').evaluate('''e=>({
                        name:getComputedStyle(e).animationName,duration:parseFloat(getComputedStyle(e).animationDuration)})''')

                assert pump_reading(25)=={'name':'symcon-modern-rotate','duration':2.39}
                slow_flow=page.locator('#gHK1 > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')
                assert slow_flow==2.8
                assert pump_reading(75)=={'name':'symcon-modern-rotate','duration':1.16}
                assert page.locator('#gHK1 > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==slow_flow
                # The primary flow follows its actual measurement, independently of %.
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==2.8
                pump_reading(25,flow=300)
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==5.6
                pump_reading(25,flow=1200)
                assert page.locator('#main > .wp-live-flow').first.evaluate('e=>parseFloat(getComputedStyle(e).animationDuration)')==1.4
                assert pump_reading(0)['name']=='none'
                assert page.locator('#gHK1 > .wp-live-flow').count()==2  # Actual measured flow still feeds the direct circuit.
                assert pump_reading(75,running=False)['name']=='none'
                assert page.locator('#gHK1 > .wp-live-flow').count()==2
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

                # Server-held temperatures survive DHW loading and the return to heating.
                hold=json.loads(Path(artifacts,'heating-hold.json').read_text())
                for stage in ['normal','hotwater','cooldown','released','buffered']:
                    sample=hold[stage]
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',sample)
                    assert page.locator('#gPrimaryWaterPump').is_visible()
                    assert page.locator('#textPrimaryPumpValue').text_content()=='24.4 %'
                    assert page.locator('#primaryPumpRotor').evaluate('e=>getComputedStyle(e).animationName')=='symcon-modern-rotate'
                    assert not page.locator('#gHKPumpIcon1').is_visible()
                    assert not page.locator('#gHKPumpIcon2').is_visible()
                    assert not page.locator('#gHK3').is_visible()
                    if stage in ['hotwater','cooldown']:
                        assert page.locator('#textSupplyTemperatureHeating').text_content()=='VL: 31.6 °C ⏸'
                        assert page.locator('#textSupplyTemperatureHeating2').text_content()=='VL: 28.5 °C ⏸'
                        assert page.locator('#textSupplyTemperatureHeating').get_attribute('data-held')=='true'
                    if stage=='hotwater':
                        assert page.locator('#textWPSupplyTemperature').text_content()=='VL: 51.1 °C'
                        assert page.locator('#switchValveFlow').get_attribute('data-target')=='hotwater'
                        assert page.locator('#gNoBuffer .wp-live-flow,#gHeating .wp-live-flow').count()==0
                        assert page.locator('#main > .wp-live-flow').count()==2
                        assert page.locator('#gDHW > .wp-live-flow').count()==2
                        screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                        if screenshot_dir: page.screenshot(path=str(Path(screenshot_dir)/f'dhw-priority-{width}.png'))
                    if stage=='cooldown':
                        assert re.fullmatch(r'VL: 40(?:\.0)? °C',page.locator('#textWPSupplyTemperature').text_content())
                        assert page.locator('#gDHW > .wp-live-flow').count()==0
                    if stage=='released':
                        assert page.locator('#textSupplyTemperatureHeating').text_content()=='VL: 31.3 °C'
                        assert page.locator('#textSupplyTemperatureHeating').get_attribute('data-held')=='false'
                        assert page.locator('#gHK1 > .wp-live-flow').count()==2
                    if stage=='buffered':
                        assert page.locator('#textSupplyTemperatureHeating').text_content()=='VL: 31.3 °C ⏸'
                        assert page.locator('#pathBufferChargeSupply + .wp-live-flow,#pathBufferChargeReturn + .wp-live-flow').count()==0
                        assert page.locator('#pathBufferManifoldSupply + .wp-live-flow,#pathBufferManifoldReturn + .wp-live-flow,#gHK1 > .wp-live-flow').count()==0
                        assert page.locator('#gDHW > .wp-live-flow').count()==2
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
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',web_payload)
                    page.locator('#gSettings').dispatch_event('click')
                    page.wait_for_function('!document.querySelector("#wp-luxtronik-controls").disabled')
                    palette=page.locator('#wp-luxtronik-dialog').evaluate('e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color})')
                    assert palette==({'background':'rgb(24, 34, 47)','color':'rgb(230, 237, 244)'} if theme=='dark'
                                     else {'background':'rgb(248, 250, 252)','color':'rgb(31, 41, 55)'}),palette
                    assert page.locator('#wp-mode-menu').evaluate('e=>({background:getComputedStyle(e).backgroundColor,color:getComputedStyle(e).color})')==palette
                    assert page.locator('#wp-luxtronik-password').count()==0
                    screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                    if screenshot_dir: page.screenshot(path=str(Path(screenshot_dir)/f'lux-theme-{theme}-{width}.png'))
                    page.locator('#wp-luxtronik-close').click()
                    page.evaluate('(p)=>window.handleMessage({type:"update",config:p.config})',payload)
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
            # Optional second mixed circuit; buffer stays in the return only.
            for width in [1200,390]:
                mixed=browser.new_page(viewport={'width':width,'height':850})
                mixed.goto(f'http://127.0.0.1:{server.server_port}/mixed-circuits.html')
                original=mixed.evaluate("""()=>JSON.parse([...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init(')).textContent.trim().slice('SymconHeatPump.init('.length,-2))""")
                for dual in [False,True]:
                    for has_buffer in [False,True]:
                        sample=json.loads(json.dumps(original));sample['config']['tankHP']=has_buffer
                        sample['config']['heatingCircuitType2']='radiator' if dual else 'off'
                        sample['data'][sample['config']['supplyTemperatureHeating']]=sample['data']['mixerActual1' if dual else 'supplyTemperature']
                        mixed.evaluate('(p)=>window.handleMessage(p)',sample)
                        assert mixed.locator('#gHK1').is_visible()
                        assert mixed.locator('#gHK2').is_visible()==dual and not mixed.locator('#gHK3').is_visible()
                        assert not mixed.locator('#gHKPumpIcon1').is_visible()
                        assert mixed.locator('[id^="gMixerValveSymbol"]').count()==0
                        assert mixed.locator('[id^="textHeatingCircuitSource"]').count()==(2 if dual else 0)
                        if dual:
                            assert mixed.locator('#textHeatingCircuitSource1').text_content()=='ab Mischer 1'
                            assert mixed.locator('#textHeatingCircuitSource2').text_content()=='ab Mischer 2'
                            assert '30.0' in mixed.locator('#textSupplyTemperatureHeating').text_content()
                            assert '35.0' in mixed.locator('#textSupplyTemperatureHeating2').text_content()
                        if has_buffer:
                            assert mixed.locator('#pathBufferChargeReturn').get_attribute('d')=='M810 466V785'
                            assert mixed.locator('#pathBufferManifoldReturn').get_attribute('d')==('M990 360V395H895' if dual else 'M990 295V335H895')
                            assert mixed.locator('#pathBufferManifoldSupply').get_attribute('d')==''
                        assert mixed.locator('#gReturnBufferBypass').count()==0
                        assert mixed.locator('#gBuffer').is_visible()==has_buffer
                        assert mixed.locator('#gNoBuffer').is_visible()!=has_buffer
                        assert mixed.locator('#textComputedCOP').text_content()=='4.25'
                        assert mixed.locator('#textComputedJAZ').text_content()=='3.75'
                mixed.close()
            print(json.dumps({'optional_second_outlet':'PASS','series_return_buffer':'PASS','DHW_temperature_hold':'PASS'}))

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
                assert frequency.locator('#textCompressorSpeedValue').text_content()=='55 rpm'
                assert frequency.locator('#textCompressorFrequencyTarget').count()==0
                assert frequency.locator('#pathCompressor').evaluate('e=>getComputedStyle(e).animationName')=='symcon-modern-rotate'
                assert frequency.locator('#textHeatPumpCycle').text_content()=='Läuft seit 2h 3m'
                assert frequency.locator('#gHeatPumpCycle > text').count()==1
                assert frequency.locator('#textHeatPumpCycle').evaluate('e=>e.getBBox().width')<=175.5
                update=json.loads(Path(artifacts,'compressor-frequency-update.json').read_text())
                frequency.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',update)
                assert frequency.locator('#textCompressorSpeedValue').text_content()=='0 rpm'
                assert frequency.locator('#textCompressorFrequencyTarget').count()==0
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
                assert heaters.locator('#wp-mode-menu .wp-mode-title').all_text_contents()==['Heizstäbe']
                assert heaters.locator('#wp-mode-menu .wp-heater-name').all_text_contents()==['Heizstab 1','Heizstab 2','Heizstab 3']
                assert heaters.locator('#wp-mode-menu .wp-heater-row button').all_text_contents()==['Ein','Ein','Aus']
                assert heaters.locator('#wp-mode-menu .wp-heater-power').all_text_contents()==['0.00 kW','0.00 kW','1.36 kW']
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
                assert service.locator('#textSuctionTemperature').text_content()=='Ansaug: 24.4 °C'
                assert service.evaluate("document.getElementById('textSuctionTemperature').getBoundingClientRect().right < document.getElementById('textHotGasTemperature').getBoundingClientRect().left")
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
                assert service.locator('#textSuctionTemperature').text_content()=='Ansaug: 0.0 °C'
                assert service.locator('#textFanSpeed').text_content()=='Lüfter: 0 rpm'
                assert service.locator('#textTimeUntilDefrost').text_content()=='Abtauen in 0h 0m'
                missing=json.loads(Path(artifacts,'service-values-missing.json').read_text())
                service.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',missing)
                assert service.locator('#textHotGasTemperature,#textSuctionTemperature,#textFanSpeed,#textTimeUntilDefrost').count()==0
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
                assert '34.5' in shi.locator('#textSupplyTemperatureHeating').text_content()
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
                assert hybrid.locator('#textHeatingFlow').get_attribute('x')=='710'
                assert hybrid.locator('#textHeatingFlow').get_attribute('y')=='752'
                assert hybrid.locator('#textCompressorSpeedValue').text_content()=='55 rpm'
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
                assert '25.0' in internal.locator('#textSupplyTemperatureHeating').text_content()
                assert internal.locator('#textHPStatus').text_content()=='Keine Anforderung'
                assert internal.locator('#textInputPower').text_content()=='1.50 kW'
                assert internal.locator('#textHeatingFlow').text_content()=='607 l/h'
                assert '48.5' in internal.locator('#textDHWIst').text_content()
                assert internal.locator('#gMixerValveSymbol1').count()==0
                assert internal.locator('#textHeatingPumpSpeed').count()==0
                internal.evaluate('(p)=>window.handleMessage(p)',internal_update)
                assert '25.1' in internal.locator('#textSupplyTemperatureHeating').text_content()
                internal.close()

            # Optional circulation and actual storage/solar signals use the same internal cache.
            for width in [1200,390]:
                comp=browser.new_page(viewport={'width':width,'height':850})
                comp.goto(f'http://127.0.0.1:{server.server_port}/components.html')
                assert comp.locator('#textBufferReturnTemperature').count()==0
                assert '42.0' in comp.locator('#textSolarStorageTemperature').text_content()
                assert comp.locator('#textThermalSolarPumpSpeed').text_content()==''
                assert not comp.locator('#textSolarPumpLabel').is_visible()
                assert '31.7' in comp.locator('#textPrimaryPumpValue').text_content()
                assert comp.locator('#textHeatingPumpSpeed').count()==0
                assert comp.locator('#gCirculatingPumpVisual').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('[id^="pathHKMixingReturn"],[id^="gMixerValveSymbol"]').count()==0
                original=comp.evaluate("""()=>JSON.parse([...document.scripts].find(s=>s.textContent.trim().startsWith('SymconHeatPump.init(')).textContent.trim().slice('SymconHeatPump.init('.length,-2))""")
                changed=json.loads(json.dumps(original))
                changed['config'].update(circulation=False,solarTarget='buffer',tankWW=False)
                comp.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert comp.locator('#gCirculatingPumpVisual').evaluate('e=>getComputedStyle(e).display')=='none'
                assert comp.locator('#gSolar').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gSolar > path.sol').get_attribute('d')=='M1088 616H1260M1260 756H1088'
                assert_solar_pipe_clearance(comp.locator('#gSolar > path.sol').get_attribute('d'))
                screenshot_dir=os.environ.get('WP_BROWSER_SCREENSHOT_DIR')
                if screenshot_dir: comp.screenshot(path=str(Path(screenshot_dir)/f'solar-buffer-{width}.png'))
                changed['config'].update(tankHP=False,tankWW=False,solarTarget='buffer')
                comp.evaluate('(p)=>window.handleMessage({type:"update",config:p.config,data:p.data,controls:p.controls})',changed)
                assert comp.locator('#gSolar').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gDHW').evaluate('e=>getComputedStyle(e).display')!='none'
                assert comp.locator('#gSolar > path.sol').get_attribute('d')=='M1088 616H1260M1260 756H1088'
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

            # Server-side WebSocket readings appear without any diagnostic click or browser socket.
            for width in [1200,390]:
                readings=browser.new_page(viewport={'width':width,'height':850})
                opened=[]
                readings.on('websocket',lambda socket:opened.append(socket.url))
                readings.goto(f'http://127.0.0.1:{server.server_port}/web-eev.html')
                assert readings.locator('#textExpansionValveOpening').text_content()=='42.9 %'
                readings.evaluate('(p)=>window.handleMessage(p)',json.loads(Path(artifacts,'web-eev-cooling.json').read_text()))
                assert readings.locator('#textExpansionValveOpening').text_content()=='100.0 %'
                assert opened==[],'EEV measurement incorrectly depends on a browser WebSocket'
                readings.close()

            # Diagnostic tools belong to configuration; visualization retains only the LUX display.
            for width in [1200,390]:
                faults=browser.new_page(viewport={'width':width,'height':850})
                faults.goto(f'http://127.0.0.1:{server.server_port}/configured.html')
                assert faults.locator('#wp-eev-test, #wp-web-test, #wp-eev-diagnostic').count()==0
                history={'available':True,'source':'WebSocket','entries':[
                    {'date':'16.02.24 14:35:19','code':718,'text':'Max. Aussentemp. (718)'},
                    {'date':'16.02.24 14:27:33','code':711,'text':'<img src=x onerror="window.faultInjected=true"> (711)'}]}
                row=json.loads(json.dumps(payload))
                row['config'].update(computedCOP='rowCOP',computedJAZ='rowJAZ')
                row['data']['rowCOP']={'value':5.7,'formatted':'5.7'}
                row['data']['rowJAZ']={'value':3.3,'formatted':'3.3'}
                faults.evaluate('(p)=>window.handleMessage({config:p.config,data:p.data})',row)
                faults.evaluate('(history)=>window.handleMessage({controls:{faultHistory:history}})',history)
                assert faults.locator('#textFaultHistory').text_content()=='Fehler:2'
                assert faults.locator('#gEfficiency #buttonFaultHistory').count()==1
                assert faults.locator('#textHPStatus').get_attribute('y')=='185'
                assert faults.locator('#textFaultHistory').get_attribute('y')==faults.locator('#textComputedCOP').get_attribute('y')==faults.locator('#textComputedJAZ').get_attribute('y')
                faults.locator('#buttonFaultHistory').click()
                assert faults.locator('#wp-fault-dialog').is_visible()
                assert faults.locator('#wp-fault-list li').count()==2
                assert 'Code 718' in faults.locator('#wp-fault-list').text_content()
                assert 'Max. Aussentemp.' in faults.locator('#wp-fault-list').text_content()
                assert faults.locator('#wp-fault-list img').count()==0
                assert not faults.evaluate('Boolean(window.faultInjected)')
                history['entries']=[]
                faults.evaluate('(history)=>window.handleMessage({controls:{faultHistory:history}})',history)
                assert 'Keine gespeicherten' in faults.locator('#wp-fault-list').text_content()
                faults.locator('#wp-fault-close').click()
                assert not faults.locator('#wp-fault-dialog').is_visible()
                faults.close()
            print(json.dumps({'configuration_diagnostics_only':'PASS','fault_history_codes_and_text':'PASS','fault_text_escaping':'PASS'}))

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
