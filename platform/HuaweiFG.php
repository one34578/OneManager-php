<?php
global $contextUserData;

function printInput($event, $context)
{
    $tmp['eventID'] = $context->geteventID();
    $tmp['RemainingTimeInMilliSeconds'] = $context->getRemainingTimeInMilliSeconds();
    $tmp['AccessKey'] = $context->getAccessKey();
    $tmp['SecretKey'] = $context->getSecretKey();
    $tmp['UserData']['HW_urn'] = $context->getUserData('HW_urn');
    $tmp['FunctionName'] = $context->getFunctionName();
    $tmp['RunningTimeInSeconds'] = $context->getRunningTimeInSeconds();
    $tmp['Version'] = $context->getVersion();
    $tmp['MemorySize'] = $context->getMemorySize();
    $tmp['CPUNumber'] = $context->getCPUNumber();
    $tmp['ProjectID'] = $context->getProjectID();
    $tmp['Package'] = $context->Package();
    $tmp['Token'] = $context->getToken();
    $tmp['Logger'] = $context->getLogger();

    if (strlen(json_encode($event['body']))>500) $event['body']=substr($event['body'],0,strpos($event['body'],'base64')+30) . '...Too Long!...' . substr($event['body'],-50);
    echo urldecode(json_encode($event, JSON_PRETTY_PRINT)) . '
 
' . urldecode(json_encode($tmp, JSON_PRETTY_PRINT)) . '
 
';
}

function GetGlobalVariable($event)
{
    $_GET = $event['queryStringParameters'];
    $postbody = explode("&",$event['body']);
    foreach ($postbody as $postvalues) {
        $pos = strpos($postvalues,"=");
        $_POST[urldecode(substr($postvalues,0,$pos))]=urldecode(substr($postvalues,$pos+1));
    }
    $cookiebody = explode("; ",$event['headers']['cookie']);
    foreach ($cookiebody as $cookievalues) {
        $pos = strpos($cookievalues,"=");
        $_COOKIE[urldecode(substr($cookievalues,0,$pos))]=urldecode(substr($cookievalues,$pos+1));
    }
    $_SERVER['HTTP_USER_AGENT'] = $event['headers']['user-agent'];
    $_SERVER['HTTP_TRANSLATE'] = $event['headers']['translate'];//'f'
    $_SERVER['_APP_SHARE_DIR'] = '/var/share/CFF/processrouter';
}

function GetPathSetting($event, $context)
{
    $_SERVER['firstacceptlanguage'] = strtolower(splitfirst(splitfirst($event['headers']['accept-language'],';')[0],',')[0]);
    $_SERVER['function_name'] = $context->getFunctionName();
    $_SERVER['ProjectID'] = $context->getProjectID();
    $host_name = $event['headers']['host'];
    $_SERVER['HTTP_HOST'] = $host_name;
    $path = path_format($event['pathParameters'][''].'/');
    $_SERVER['base_path'] = path_format($event['path'].'/');
    if (  $_SERVER['base_path'] == $path ) {
        $_SERVER['base_path'] = '/';
    } else {
        $_SERVER['base_path'] = substr($_SERVER['base_path'], 0, -strlen($path));
        if ($_SERVER['base_path']=='') $_SERVER['base_path'] = '/';
    }
    if (substr($path,-1)=='/') $path=substr($path,0,-1);
    $_SERVER['is_guestup_path'] = is_guestup_path($path);
    $_SERVER['PHP_SELF'] = path_format($_SERVER['base_path'] . $path);
    $_SERVER['REMOTE_ADDR'] = $event['headers']['x-real-ip'];
    $_SERVER['HTTP_X_REQUESTED_WITH'] = $event['headers']['x-requested-with'];
    return $path;
}

function getConfig($str, $disktag = '')
{
    global $InnerEnv;
    global $Base64Env;
    global $contextUserData;
    if (in_array($str, $InnerEnv)) {
        if ($disktag=='') $disktag = $_SERVER['disktag'];
        $env = json_decode($contextUserData->getUserData($disktag), true);
        if (isset($env[$str])) {
            if (in_array($str, $Base64Env)) return equal_replace($env[$str],1);
            else return $env[$str];
        }
    } else {
        if (in_array($str, $Base64Env)) return equal_replace($contextUserData->getUserData($str),1);
        else return $contextUserData->getUserData($str);
    }
    return '';
}

function setConfig($arr, $disktag = '')
{
    global $InnerEnv;
    global $Base64Env;
    global $contextUserData;
    if ($disktag=='') $disktag = $_SERVER['disktag'];
    $disktags = explode("|",getConfig('disktag'));
    $diskconfig = json_decode($contextUserData->getUserData($disktag), true);
    $tmp = [];
    $indisk = 0;
    $oparetdisk = 0;
    foreach ($arr as $k => $v) {
        if (in_array($k, $InnerEnv)) {
            if (in_array($k, $Base64Env)) $diskconfig[$k] = equal_replace($v);
            else $diskconfig[$k] = $v;
            $indisk = 1;
        } elseif ($k=='disktag_add') {
            array_push($disktags, $v);
            $oparetdisk = 1;
        } elseif ($k=='disktag_del') {
            $disktags = array_diff($disktags, [ $v ]);
            $tmp[$v] = '';
            $oparetdisk = 1;
        } else {
            if (in_array($k, $Base64Env)) $tmp[$k] = equal_replace($v);
            else $tmp[$k] = $v;
        }
    }
    if ($indisk) {
        $diskconfig = array_filter($diskconfig, 'array_value_isnot_null');
        ksort($diskconfig);
        $tmp[$disktag] = json_encode($diskconfig);
    }
    if ($oparetdisk) {
        $disktags = array_unique($disktags);
        foreach ($disktags as $disktag) if ($disktag!='') $disktag_s .= $disktag . '|';
        if ($disktag_s!='') $tmp['disktag'] = substr($disktag_s, 0, -1);
        else $tmp['disktag'] = '';
    }
//    echo '正式设置：'.json_encode($tmp,JSON_PRETTY_PRINT).'
//';
    $response = updateEnvironment($tmp, getConfig('HW_urn'), getConfig('HW_name'), getConfig('HW_pwd'));
    // WaitSCFStat();
    return $response;
}

function WaitSCFStat()
{
    $trynum = 0;
    while( json_decode(getfunctioninfo($_SERVER['function_name'], $_SERVER['Region'], $_SERVER['namespace'], getConfig('SecretId'), getConfig('SecretKey')),true)['Response']['Status']!='Active' ) echo '
'.++$trynum;
}

function install()
{
    global $constStr;
    if ($_GET['install2']) {
        $tmp['admin'] = $_POST['admin'];
        setConfig($tmp);
        if (needUpdate()) {
            OnekeyUpate();
            return message('update to github version, reinstall.<script>document.cookie=\'language=; path=/\';</script><meta http-equiv="refresh" content="3;URL=' . $url . '">', 'Program updating', 201);
        }
        return output('Jump<script>document.cookie=\'language=; path=/\';</script><meta http-equiv="refresh" content="3;URL=' . path_format($_SERVER['base_path'] . '/') . '">', 302);
    }
    if ($_GET['install1']) {
        //if ($_POST['admin']!='') {
            //$tmp['language'] = $_POST['language'];
            $tmp['timezone'] = $_COOKIE['timezone'];
            $tmp['HW_urn'] = getConfig('HW_urn');
            if ($tmp['HW_urn']=='') {
                $tmp['HW_urn'] = $_POST['HW_urn'];
            }
            $tmp['HW_name'] = getConfig('HW_name');
            if ($tmp['HW_name']=='') {
                $tmp['HW_name'] = $_POST['HW_name'];
            }
            $tmp['HW_pwd'] = getConfig('HW_pwd');
            if ($tmp['HW_pwd']=='') {
                $tmp['HW_pwd'] = $_POST['HW_pwd'];
            }
            //$response = json_decode(SetbaseConfig($tmp, $HW_urn, $HW_name, $HW_pwd), true)['Response'];
            $response = SetbaseConfig($tmp, $tmp['HW_urn'], $tmp['HW_name'], $tmp['HW_pwd']);
            if (api_error($response)) {
                $html = api_error_msg($response);
                $title = 'Error';
                return message($html, $title, 201);
            } else {
                $html .= '
    <form action="?install2" method="post" onsubmit="return notnull(this);">
        <label>'.getconstStr('SetAdminPassword').':<input name="admin" type="password" placeholder="' . getconstStr('EnvironmentsDescription')['admin'] . '" size="' . strlen(getconstStr('EnvironmentsDescription')['admin']) . '"></label><br>
        <input type="submit" value="'.getconstStr('Submit').'">
    </form>
    <script>
        function notnull(t)
        {
            if (t.admin.value==\'\') {
                alert(\''.getconstStr('SetAdminPassword').'\');
                return false;
            }
            return true;
        }
    </script>';
                $title = getconstStr('SetAdminPassword');
                return message($html, $title, 201);
            }
        //}
    }
    if ($_GET['install0']) {
        $html .= '
    <form action="?install1" method="post" onsubmit="return notnull(this);">
language:<br>';
        foreach ($constStr['languages'] as $key1 => $value1) {
            $html .= '
        <label><input type="radio" name="language" value="'.$key1.'" '.($key1==$constStr['language']?'checked':'').' onclick="changelanguage(\''.$key1.'\')">'.$value1.'</label><br>';
        }
        if (getConfig('HW_urn')==''||getConfig('HW_name')==''||getConfig('HW_pwd')=='') $html .= '
        在函数代码操作页上方找到URN，鼠标放上去后显示URN，复制填入：<br>
        <label>URN:<input name="HW_urn" type="text" placeholder="" size=""></label><br>
        <a href="https://console.huaweicloud.com/iam/#/mine/apiCredential" target="_blank">点击链接</a>找到用户名，填入：<br>
        <label>账号名:<input name="HW_name" type="text" placeholder="" size=""></label><br>
        <label>密码:<input name="HW_pwd" type="password" placeholder="" size=""></label><br>';
        $html .= '
        <input type="submit" value="'.getconstStr('Submit').'">
    </form>
    <script>
        var nowtime= new Date();
        var timezone = 0-nowtime.getTimezoneOffset()/60;
        var expd = new Date();
        expd.setTime(expd.getTime()+(2*60*60*1000));
        var expires = "expires="+expd.toGMTString();
        document.cookie="timezone="+timezone+"; path=/; "+expires;
        function changelanguage(str)
        {
            document.cookie=\'language=\'+str+\'; path=/\';
            location.href = location.href;
        }
        function notnull(t)
        {';
        if (getConfig('HW_urn')==''||getConfig('HW_name')==''||getConfig('HW_pwd')=='') $html .= '
            if (t.HW_urn.value==\'\') {
                alert(\'input URN\');
                return false;
            }
            if (t.HW_name.value==\'\') {
                alert(\'input name\');
                return false;
            }
            if (t.HW_pwd.value==\'\') {
                alert(\'input pwd\');
                return false;
            }';
        $html .= '
            return true;
        }
    </script>';
        $title = getconstStr('SelectLanguage');
        return message($html, $title, 201);
    }
    $html .= '<a href="?install0">'.getconstStr('ClickInstall').'</a>, '.getconstStr('LogintoBind');
    $title = 'Error';
    return message($html, $title, 201);
}

function getIAMToken($urn, $name, $pwd)
{
    if ($_SERVER['HWtoken']!='') return $_SERVER['HWtoken'];
    // https://iam.ap-southeast-1.myhuaweicloud.com/v3/auth/tokens
/*
{
    auth: {
        identity: {
            methods: [password]
            password: {
                user: {
                    domain: {
                        name:qkqpttgf
                    }
                    name:qkqpttgf
                    password:string
                }
            }
        }
        scope: {
            //domain: {
            //    id:string
            //    name:string
            //}
            project: {
                id:string
                //name:string
            }
        }
    }
}
*/
    $URN = explode(':', $urn);
    $Region = $URN[2];
    $project_id = $URN[3];
    $url = 'https://iam.' . $Region . '.myhuaweicloud.com/v3/auth/tokens';
    $data['auth']['identity']['methods'][0] = 'password';
    $data['auth']['identity']['password']['user']['domain']['name'] = $name;
    $data['auth']['identity']['password']['user']['name'] = $name;
    $data['auth']['identity']['password']['user']['password'] = $pwd;
    $data['auth']['scope']['project']['id'] = $project_id;
//echo $url.json_encode($data);
    $token = curl_request($url, json_encode($data), [ 'Content-Type' => 'application/json;charset=utf8' ], 1);
//echo json_encode($token, JSON_PRETTY_PRINT);
    $_SERVER['HWtoken'] = $token['returnhead']['X-Subject-Token'];
    return $token['returnhead']['X-Subject-Token'];
}

function put2url($url, $data, $headers)
{
    $sendHeaders = array();
    foreach ($headers as $headerName => $headerVal) {
        $sendHeaders[] = $headerName . ': ' . $headerVal;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    //curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
    $response = curl_exec($ch);
    curl_close($ch);
    //echo $response;
    return $response;
}

function ReorganizeDate($arr)
{
    $str = '';
    ksort($arr);
    foreach ($arr as $k1 => $v1) {
        $str .= '&' . $k1 . '=' . $v1;
    }
    $str = substr($str, 1); // remove first '&'. 去掉第一个&
    return $str;
}

function getfunctioninfo($HW_urn, $HW_name, $HW_pwd)
{
    $HWtoken = getIAMToken($HW_urn, $HW_name, $HW_pwd);
    $URN = explode(':', $HW_urn);
    $Region = $URN[2];
    $project_id = $URN[3];
    $url = 'https://functiongraph.' . $Region . '.myhuaweicloud.com/v2/' . $project_id . '/fgs/functions/' . $HW_urn . '/config';
    $header['X-Auth-Token'] = $HWtoken;
    $header['Content-Type'] = 'application/json;charset=utf8';
    return curl_request($url, false, $header)['body'];
}

function updateEnvironment($Envs, $HW_urn, $HW_name, $HW_pwd)
{
    //echo json_encode($Envs,JSON_PRETTY_PRINT);
    global $contextUserData;
    $tmp_env = json_decode(json_decode(getfunctioninfo($HW_urn, $HW_name, $HW_pwd),true)['user_data'],true);
    foreach ($Envs as $key1 => $value1) {
        $tmp_env[$key1] = $value1;
    }
    $tmp_env = array_filter($tmp_env, 'array_value_isnot_null'); // remove null. 清除空值
    ksort($tmp_env);

    $HWtoken = getIAMToken($HW_urn, $HW_name, $HW_pwd);

    $URN = explode(':', $HW_urn);
    $Region = $URN[2];
    $project_id = $URN[3];
    $url = 'https://functiongraph.' . $Region . '.myhuaweicloud.com/v2/' . $project_id . '/fgs/functions/' . $HW_urn . '/config';
    $header['X-Auth-Token'] = $HWtoken;
    $header['Content-Type'] = 'application/json;charset=utf8';
    $tmpdata['handler'] = 'index.handler';
    $tmpdata['memory_size'] = $contextUserData->getMemorySize()+1-1;
    $tmpdata['runtime'] = 'PHP7.3';
    $tmpdata['timeout'] = $contextUserData->getRunningTimeInSeconds()+1-1;
    $tmpdata['user_data'] = json_encode($tmp_env);

    return put2url($url, json_encode($tmpdata), $header);
}

function SetbaseConfig($Envs, $HW_urn, $HW_name, $HW_pwd)
{
    //echo json_encode($Envs,JSON_PRETTY_PRINT);

    $tmp_env = json_decode(json_decode(getfunctioninfo($HW_urn, $HW_name, $HW_pwd),true)['user_data'],true);
    foreach ($Envs as $key1 => $value1) {
        $tmp_env[$key1] = $value1;
    }
    $tmp_env = array_filter($tmp_env, 'array_value_isnot_null'); // remove null. 清除空值
    ksort($tmp_env);

    //if (getConfig('HWtokenexp')!=''&&time()<getConfig('HWtokenexp')) $HWtoken = getConfig('HWtoken');
    //else 
    $HWtoken = getIAMToken($HW_urn, $HW_name, $HW_pwd);
    //$Envs['HWtoken'] = $HWtoken;
    //return $HWtoken;
// https://functiongraph.cn-north-4.myhuaweicloud.com/v2/{project_id}/fgs/functions/{function_urn}/config
    $URN = explode(':', $HW_urn);
    $Region = $URN[2];
    $project_id = $URN[3];
    $url = 'https://functiongraph.' . $Region . '.myhuaweicloud.com/v2/' . $project_id . '/fgs/functions/' . $HW_urn . '/config';
    $header['X-Auth-Token'] = $HWtoken;
    $header['Content-Type'] = 'application/json;charset=utf8';
    $tmpdata['handler'] = 'index.handler';
    $tmpdata['memory_size'] = 128;
    $tmpdata['runtime'] = 'PHP7.3';
    $tmpdata['timeout'] = 30;
    $tmpdata['user_data'] = json_encode($tmp_env);

    return put2url($url, json_encode($tmpdata), $header);
}

function updateProgram($HW_urn, $HW_name, $HW_pwd, $source)
{
    $HWtoken = getIAMToken($HW_urn, $HW_name, $HW_pwd);

    $URN = explode(':', $HW_urn);
    $Region = $URN[2];
    $project_id = $URN[3];
    $url = 'https://functiongraph.' . $Region . '.myhuaweicloud.com/v2/' . $project_id . '/fgs/functions/' . $HW_urn . '/code';
    $header['X-Auth-Token'] = $HWtoken;
    $header['Content-Type'] = 'application/json;charset=utf8';
    $tmpdata['code_type'] = 'zip';
    $tmpdata['func_code']['file'] = base64_encode( file_get_contents($source) );

    return put2url($url, json_encode($tmpdata), $header);
}

function api_error($response)
{
    return isset($response['Error']);
}

function api_error_msg($response)
{
    return $response['Error']['Code'] . '<br>
' . $response['Error']['Message'] . '<br><br>
function_name:' . $_SERVER['function_name'] . '<br>
Region:' . $_SERVER['Region'] . '<br>
namespace:' . $_SERVER['namespace'] . '<br>
<button onclick="location.href = location.href;">'.getconstStr('Refresh').'</button>';
}

function setConfigResponse($response)
{
    return json_decode( $response, true )['Response'];
}

function OnekeyUpate($auth = 'qkqpttgf', $project = 'OneManager-php', $branch = 'master')
{
    $source = '/tmp/code.zip';
    $outPath = '/tmp/';

    // 从github下载对应tar.gz，并解压
    $url = 'https://github.com/' . $auth . '/' . $project . '/tarball/' . urlencode($branch) . '/';
    $tarfile = '/tmp/github.tar.gz';
    file_put_contents($tarfile, file_get_contents($url));
    $phar = new PharData($tarfile);
    $html = $phar->extractTo($outPath, null, true);//路径 要解压的文件 是否覆盖

    // 获取解压出的目录名
/*
    @ob_start();
    passthru('ls /tmp | grep '.$auth.'-'.$project.'',$stat);
            $html.='状态：' . $stat . '
    结果：
    ';
    $archivefolder = ob_get_clean();
    if (substr($archivefolder,-1)==PHP_EOL) $archivefolder = substr($archivefolder, 0, -1);
    $outPath .= $archivefolder;
    $html.=htmlspecialchars($archivefolder);
    //return $html;
*/
    $tmp = scandir($outPath);
    $name = $auth.'-'.$project;
    foreach ($tmp as $f) {
        if ( substr($f, 0, strlen($name)) == $name) {
            $outPath .= $f;
            break;
        }
    }

    // 将目录中文件打包成zip
    //$zip=new ZipArchive();
    $zip=new PharData($source);
    //if($zip->open($source, ZipArchive::CREATE)){
        addFileToZip($zip, $outPath); //调用方法，对要打包的根目录进行操作，并将ZipArchive的对象传递给方法
    //    $zip->close(); //关闭处理的zip文件
    //}

    return updateProgram(getConfig('HW_urn'), getConfig('HW_name'), getConfig('HW_pwd'), $source);
}

function addFileToZip($zip, $rootpath, $path = '')
{
    if (substr($rootpath,-1)=='/') $rootpath = substr($rootpath, 0, -1);
    if (substr($path,0,1)=='/') $path = substr($path, 1);
    $handler=opendir(path_format($rootpath.'/'.$path)); //打开当前文件夹由$path指定。
    while($filename=readdir($handler)){
        if($filename != "." && $filename != ".."){//文件夹文件名字为'.'和‘..’，不要对他们进行操作
            $nowname = path_format($rootpath.'/'.$path."/".$filename);
            if(is_dir($nowname)){// 如果读取的某个对象是文件夹，则递归
                $zip->addEmptyDir($path."/".$filename);
                addFileToZip($zip, $rootpath, $path."/".$filename);
            }else{ //将文件加入zip对象
                $newname = $path."/".$filename;
                if (substr($newname,0,1)=='/') $newname = substr($newname, 1);
                $zip->addFile($nowname, $newname);
                //$zip->renameName($nowname, $newname);
            }
        }
    }
    @closedir($path);
}
