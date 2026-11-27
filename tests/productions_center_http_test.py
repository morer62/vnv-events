"""Loopback integration of the VNV panel, real session middleware, Twig and private VNV-Q registry.
Requires php + php-pdo_sqlite, local disposable MariaDB, VNV_COMPOSER_AUTOLOAD and VNV_Q_TEST_ROOT.
"""
import datetime
import http.cookiejar
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
AUTOLOAD = Path(os.getenv("VNV_COMPOSER_AUTOLOAD", str(ROOT / "vendor/autoload.php"))).resolve()
PRIVATE = Path(os.environ["VNV_Q_TEST_ROOT"]).resolve()
assert AUTOLOAD.is_file() and (PRIVATE / "src/ManagedWorkspaces.php").is_file()
schema = "pmc_http_test_" + secrets.token_hex(5)
local_port = int(os.getenv("VNV_PMC_TEST_PORT", "33742"))
with socket.socket() as s:
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
base = "http://127.0.0.1:" + str(port)
path = "/panel/productions-management-center"
php = os.getenv("PHP_BIN", "php")
prelude = r"""<?php
$root=getenv('TEST_VNV_ROOT');
require getenv('TEST_AUTOLOAD');
spl_autoload_register(static function($class)use($root){
    if(str_starts_with($class,'App\\')) {
        $path=$root.'/src/'.str_replace('\\','/',substr($class,4)).'.php';
        if(is_file($path)){require_once $path;}
    }
},true,true);
$base=getenv('TEST_BASE');
$_ENV['APP_URL']=$base;
$_ENV['DATABASE_URL']='mysql:host=127.0.0.1;port='.getenv('TEST_DBPORT').';dbname='.getenv('TEST_SCHEMA').';user=root;charset=utf8mb4';
$_ENV['SITE_KEY']='vnvevents'; $_ENV['SITE_BUSINESS_USER_ID']='2';
$_ENV['VNV_OPS_PRIVATE_ROOT']=getenv('TEST_Q');
$_ENV['VNV_OPS_MANAGED_DIR']=getenv('TEST_REG');
$_ENV['VNV_OPS_ACCESS_READY']='0';
preg_match_all('/\$_ENV\[\'([A-Z0-9_]+)\'\]/',file_get_contents($root.'/src/Services/ConfigService.php'),$matches);
foreach($matches[1] as $key){ $_ENV[$key]??='';}
"""
router = prelude + r"""
session_save_path(getenv('TEST_SESS'));
session_start();
$_GET['url']=trim(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),'/');
(new App\Kernel())->run();
"""
seed = prelude + r"""
$name=getenv('TEST_SCHEMA');
if(!preg_match('/^pmc_http_test_[a-f0-9]{10}$/D',$name)){exit(2);}
$db=new PDO('mysql:host=127.0.0.1;port='.getenv('TEST_DBPORT').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($argv[1]==='drop'){$db->exec("DROP DATABASE IF EXISTS \x60$name\x60");exit;}
$db->exec("CREATE DATABASE \x60$name\x60 CHARACTER SET utf8mb4"); $db->exec("USE \x60$name\x60");
preg_match("/<<<'SQL'\R(.*?)\RSQL\);/s",file_get_contents($root.'/db/migrations/20261112150000_create_commercial_partnership_crm.php'),$parts);
$db->exec($parts[1]);$db->exec(file_get_contents($root.'/db/20261125_vnv_break_club.sql'));
$db->exec(file_get_contents($root.'/db/sql/productions-center-audit.sql'));
$db->exec("CREATE TABLE users(id INT PRIMARY KEY,id_owner INT,level INT,is_active INT,name VARCHAR(100),lastname VARCHAR(100),email VARCHAR(100),password VARCHAR(255),phone VARCHAR(30),phone_validation INT DEFAULT 1,membership_due_date DATE,phone_code VARCHAR(5),membership_type VARCHAR(20) DEFAULT 'FREE');
CREATE TABLE user_feature_permissions(id_user INT, feature_slug VARCHAR(80),UNIQUE KEY perm(id_user,feature_slug));CREATE TABLE user_roles(user_id INT,role_id INT);
CREATE TABLE orders(id INT UNSIGNED PRIMARY KEY,id_owner INT);CREATE TABLE orders_staff_invites(id_order INT,id_user INT,is_confirmed INT);
CREATE TABLE orders_team_tasks(id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,id_order INT UNSIGNED NOT NULL,id_owner INT,id_user INT,id_service INT,task_description TEXT,is_done TINYINT DEFAULT 0);
INSERT INTO user_feature_permissions VALUES(44,'pmc_center'),(44,'crm_sponsors'),(44,'pmc_provision'),(46,'pmc_center');");
$p=$db->prepare('INSERT INTO users(id,id_owner,level,is_active,name,lastname,email,password) VALUES(?,?,?,1,?,?,?,?)');
foreach([[2,2,1],[44,2,4],[45,2,4],[46,3,4],[47,2,2],[48,2,5]] as [$id,$owner,$lvl]){
    $p->execute([$id,$owner,$lvl,'QA','Staff '.$id,'qa'.$id.'@example.org',password_hash(getenv('TEST_PASS'),PASSWORD_BCRYPT)]);
}
App\Services\ProductionsWorkboardSchema::apply($db);
$db->exec("INSERT INTO commercial_opportunities(id,id_owner,site_key,program,pipeline,stage,title,event_date,assigned_user_id) VALUES(501,2,'vnvevents','CORPORATE','CORPORATE','WON','QA meeting','2026-12-06',44),(502,2,'vnvevents','CORPORATE','CORPORATE','LEAD','Hidden producer',NULL,45);
INSERT INTO orders_team_tasks(id_owner,id_user,id_service,task_description,pmc_task_key,pmc_priority) VALUES(2,44,0,'Complete the role training exercise','PMC_TEAM_TRAINING','urgent');");
require_once getenv('TEST_Q').'/src/ManagedWorkspaces.php';
ManagedWorkspaces::install(getenv('TEST_REG'));
"""
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

browser_check = r"""
const {chromium}=require(process.env.VNV_PMC_PLAYWRIGHT);
const fs=require('node:fs'), path=require('node:path');
(async()=>{
  const base=process.env.TEST_BASE;
  const root=path.resolve(process.env.VNV_PMC_PUBLIC_ASSETS);
  const browser=await chromium.launch({headless:true});
  try{
    const context=await browser.newContext();
    await context.route('**/*',async route=>{
      const u=new URL(route.request().url());
      if(u.origin!==base) return route.abort();
      if(/^\/(template|assets)\//.test(u.pathname)){
        const file=path.resolve(root,'.'+u.pathname);
        if(!file.startsWith(root+path.sep)|| !fs.existsSync(file))return route.fulfill({status:404,body:''});
        return route.fulfill({path:file});
      }
      return route.continue();
    });
    await context.request.post(base+'/login',{form:{email:'qa44@example.org',password:process.env.TEST_PASS},maxRedirects:0});
    const page=await context.newPage();
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:850});
      await page.goto(base+'/panel/productions-management-center?tab=tasks',{waitUntil:'networkidle'});
      const result=await page.locator('.pmc-shell').evaluate(el=>({
        width:el.getBoundingClientRect().width,pageWidth:document.documentElement.scrollWidth,
        viewport:innerWidth,font:getComputedStyle(el).fontSize,tasks:el.querySelectorAll('table tbody tr').length
      }));
      if(result.width>width+2 || result.pageWidth>width+2 || result.tasks<1)throw new Error(JSON.stringify(result));
      await page.screenshot({path:path.join(process.env.TEST_SESS,'workboard-'+width+'.png')});
      console.log('Layout width '+width+'px, content '+result.width+'px, '+result.tasks+' visible task rows.');
    }
    await page.goto(base+'/panel/productions-management-center?tab=onboarding&account_id=1&print=1&print_level=2',{waitUntil:'networkidle'});
    if(!await page.locator('details[open]').count())throw new Error('Printable checklist is collapsed');
    await page.emulateMedia({media:'print'});
    const doc=await page.pdf({format:'A4'});
    if(doc.length<1000)throw new Error('Printable PDF missing');
    console.log('Rendered onboarding PDF ('+doc.length+' bytes).');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
"""

def req(client, route, data=None):
    encoded = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
    query = urllib.request.Request(base + route, data=encoded)
    try:
        r = client.open(query, timeout=12)
    except urllib.error.HTTPError as e:
        r = e
    with r:
        return r.status, r.read().decode("utf-8", errors="replace"), r.headers.get("Location","")

def assert_response(result, status, needle=None):
    code, body, where = result
    if code != status or (needle and needle not in body):
        text = re.sub(r"<[^>]+>", " ", body)
        raise AssertionError((code, status, needle, where, " ".join(text.split())[-900:]))
    return body

def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect)

def token(body):
    m = re.search(r'name="_csrf" value="([A-Fa-f0-9]+)"', body)
    assert m
    return m[1]

def login(uid):
    c = client()
    result = req(c, "/login", {"email": f"qa{uid}@example.org", "password": env["TEST_PASS"]})
    assert_response(result,302)
    return c

with tempfile.TemporaryDirectory(prefix="vnv-pmc-http-") as tmp:
    tmp = Path(tmp)
    for name in ("public","registry","sessions"):
        (tmp/name).mkdir()
    env = os.environ.copy()
    env.update(TEST_VNV_ROOT=str(ROOT), TEST_AUTOLOAD=str(AUTOLOAD), TEST_Q=str(PRIVATE), TEST_BASE=base,
               TEST_REG=str(tmp/"registry"), TEST_SESS=str(tmp/"sessions"), TEST_DBPORT=str(local_port),
               TEST_SCHEMA=schema, TEST_PASS=secrets.token_urlsafe(24))
    (tmp/"router.php").write_text(router, encoding="utf-8")
    (tmp/"fixture.php").write_text(seed, encoding="utf-8")
    server = None
    try:
        subprocess.run([php,str(tmp/"fixture.php"),"seed"],env=env,check=True)
        with (tmp/"server.log").open("wb") as log:
            server = subprocess.Popen([php,"-d","display_errors=0","-S",f"127.0.0.1:{port}","-t",str(tmp/"public"),str(tmp/"router.php")],
                                      env=env,stdout=log,stderr=log)
            for _ in range(100):
                try:
                    with socket.create_connection(("127.0.0.1",port),timeout=0.5): break
                except OSError: time.sleep(.05)
            guest=req(client(),path)
            assert guest[0] in (200,302) and ("Session required" in guest[1] or "/login" in guest[2])
            direction=login(2)
            team=login(44)
            for uid in (45,46,47,48):
                assert_response(req(login(uid),path),403)
            body=assert_response(req(team,path),200,"Complete the role training exercise")
            assert 'aria-label="Productions sections"' in body
            for tab in ("crm","active","past","originals","tasks","accounts","demo","onboarding","reports"):
                page=assert_response(req(team,path+"?tab="+tab),200,"Productions Management Center")
                assert "could not load its records" not in page and "registry is unavailable" not in page
            body=assert_response(req(team,path+"?tab=crm&opportunity=501"),200,"QA meeting")
            assert "Hidden producer" not in body
            t=token(body)
            assert_response(req(team,path,{"action":"work_crm_note","tab":"crm","opportunity_id":"501"}),403)
            result=req(team,path,{"_csrf":t,"tab":"active","action":"work_crm_handoff","opportunity_id":"501","date":"2026-12-06","approval_ref":"QA signed scope"})
            assert_response(result,303)
            assert_response(req(team,path+"?tab=active"),200,"QA meeting")
            selected=assert_response(req(team,path+"?tab=active&project=1"),200,"five-phase checklist")
            assert_response(req(team,path,{"_csrf":token(selected),"tab":"active","project_id":"1","action":"work_template","assignee":"44"}),303)
            queued=assert_response(req(team,path+"?tab=tasks"),200,"Define the business outcome")
            assert 'project=1#schedule-' in queued
            for account_tab in ("accounts","demo"):
                assert_response(req(direction,path+"?tab="+account_tab),200,"Create organization" if account_tab=="accounts" else "Create demo")
            demo=assert_response(req(team,path+"?tab=demo"),200,"Create demo")
            new=req(team,path,{"_csrf":token(demo),"tab":"demo","action":"account.create","name":"Integrated HTTP practice","organization_type":"Nonprofit",
                              "admin_name":"Demo Administrator","admin_email":"qa-admin@example.org","manager_id":44,"environment":"demo",
                              "demo_expiration":(datetime.date.today()+datetime.timedelta(days=20)).isoformat(),"modules[]":["projects","tasks","crm"]})
            assert_response(new,303)
            assert "account_id=" in new[2]
            view=assert_response(req(team,path+"?tab=onboarding&account_id=1&print=1&print_level=2"),200,"Integrated HTTP practice")
            assert "Print Onboarding Checklist" in view and "Required activities" in view and "<details open>" in view
            assert 'qa-admin@example.org' in view and "Enabled modules" in view
            assert_response(req(team,path+"?tab=access"),403)
            assert_response(req(direction,path+"?tab=access"),200,"Team access")
            if env.get("VNV_PMC_PLAYWRIGHT") and env.get("VNV_PMC_PUBLIC_ASSETS"):
                (tmp/"viewport.cjs").write_text(browser_check,encoding="utf-8")
                subprocess.run(["node",str(tmp/"viewport.cjs")],env=env,check=True,timeout=75)
            print("PASS: real password logins, L1, delegated L4, ungranted L4, foreign tenant, L2/L5, all 10 tabs, CSRF, native task, scoped CRM, VNV-Q demo provisioning and printable checklist.")
    except Exception:
        if (tmp/"server.log").exists():
            print((tmp/"server.log").read_text(encoding="utf-8",errors="replace")[-2200:])
        raise
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=10)
        subprocess.run([php,str(tmp/"fixture.php"),"drop"],env=env,check=True)
