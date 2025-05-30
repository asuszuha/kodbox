<?php 
/**
 * 通过libreoffice转换office文件为pdf
 * 
 * 启动进程：
 * /usr/lib64/libreoffice/program/soffice --headless --accept="socket,host=127.0.0.1,port=8100;urp;" --nofirststartwizard &
 * 转换文件：
 * /usr/lib64/libreoffice/program/soffice --headless --invisible --convert-to pdf --outdir 输出目录 源文件
 * 
 * https://blog.csdn.net/weixin_40816738/article/details/103890765
 * https://gblfy.blog.csdn.net/article/details/103861905
 * 
 * https://www.cnblogs.com/xcp19870712/p/4760842.html?_t_t_t=0.4833811022118151
 */
class officeViewerlibreOfficeIndex extends Controller {
	public function __construct() {
		parent::__construct();
        $this->pluginName = 'officeViewerPlugin';
    }

    public function index(){
		$plugin = Action($this->pluginName);
        if(!$plugin->allowExt('lb')) {
			$plugin->showTips(LNG('officeViewer.main.invalidExt'), 'LibreOffice');
		}
		if(!$this->getSoffice()){
			$plugin->showTips(LNG('officeViewer.libreOffice.sofficeError'), 'LibreOffice');
		}

        $path = $plugin->filePath($this->in['path']);
		$info = IO::info($path);
		$ext  = $info['ext'];

		// 转换文件已存在，直接输出
		$fileHash = KodIO::hashPath($info);
		$convName = "libreOffice_{$ext}_{$fileHash}.pdf";
		if (!is_dir(TEMP_FILES)) mk_dir(TEMP_FILES);
		$tempFile = TEMP_FILES . $convName;
		$tempInfo = IO::infoFull($plugin->cachePath.$convName);
		if ($tempInfo) {
			$file = Model('File')->fileInfo($tempInfo['fileID']);
			if ($file && IO::exist($file['path'])) {
				return $this->fileView($tempInfo['path'],$convName);
			}
		}

		$localFile = $this->localFile($path);
		if(!$localFile){
            $localFile = $plugin->pluginLocalFile($path);	// 下载到本地文件
        }
		// 后缀名异常时（webdav为tmp）会转换失败
		if (get_path_ext($localFile) != $ext) {
			$localFile = $localFileNew = IO::copyFile($localFile, $localFile.'.'.$ext);
		}
        $this->convert2pdf($localFile,$tempFile,$ext);
		if (isset($localFileNew)) del_file($localFileNew);

		if(@file_exists($tempFile)){
			$cachePath  = IO::move($tempFile,$plugin->cachePath);
			Cache::set('libreOffice_pdf_'.$fileHash,'yes');
			return $this->fileView($cachePath,$convName);
		}
		Cache::set('libreOffice_pdf_'.$fileHash,'no');
		del_file($tempFile);
		$plugin->showTips(LNG('officeViewer.libreOffice.convertError'), 'LibreOffice');
    }

	// 打开pdf文件
	public function fileView($path,$convName){
		// $this->in['path'] = Action('explorer.share')->linkFile($path).'&path=/'.$convName;
		$link = Action('explorer.share')->linkFile($path).'&path=/'.$convName;
		$link = APP_HOST.'#fileView&path='. rawurlencode($link);
		Action($this->pluginName)->showWebOffice('lb', $link);
		// Action('explorer.fileView')->index();
	}

	// office文件转pdf
    private function convert2pdf($file,$cacheFile,$ext){
		$command = $this->getSoffice();
		if(!$command){
			Action($this->pluginName)->showTips(LNG('officeViewer.libreOffice.sofficeError'), 'LibreOffice');
		}
		//linux下$cacheFile不可写问题，先生成到/tmp下;再复制出来
		$tempPath = $cacheFile;
		if($GLOBALS['config']['systemOS'] == 'linux' && is_writable('/tmp/')){
			mk_dir('/tmp/libreOffice');
			$tempPath = '/tmp/libreOffice/'.rand_string(15).'.pdf';
		}
        $fname = get_path_this($tempPath);
        $fpath = get_path_father($tempPath);
        // 转换类型'pdf'改为'新文件名.pdf'，会生成'源文件名.新文件名.pdf'
		$this->setLctype($fname,$file);
		$export = 'export HOME=/tmp/libreOffice && ';
        $script = $export.$command . ' --headless --invisible --convert-to '.escapeShell($fname).' '.escapeShell($file).' --outdir '.$fpath;
		$out = shell_exec($script);

        $tname = substr(end(explode('/', $file)), 0, -strlen('.'.$ext));
        $tfile = $fpath . $tname . '.' . $fname;    // 源文件名.filename.pdf
        if(!file_exists($tfile)){
            write_log('libreoffice convert error: '.$script."\n".$out,'error');
        }
		$res = move_path($tfile,$cacheFile);
		if (!$res) write_log('libreoffice move file error: '.$tfile.'=>'.$cacheFile, 'error');
	}
	// 设置地区字符集，避免中文被过滤
	private function setLctype($path,$path2=''){
		if (stripos(setlocale(LC_CTYPE, 0), 'utf-8') !== false) return;
		if (Input::check($path,'hasChinese') || ($path2 && Input::check($path2,'hasChinese'))) {
			setlocale(LC_CTYPE, "en_US.UTF-8");
		}
	}

    // 获取文件 hash
	private function localFile($path){
		$pathParse = KodIO::parse($path);
		if(!$pathParse['type']) return $path;
		
		$fileInfo = IO::info($path);
		if($fileInfo['fileID']){
			$tempInfo 	= Model('File')->fileInfo($fileInfo['fileID']);
			$fileInfo 	= IO::info($tempInfo['path']);
			$pathParse 	= KodIO::parse($tempInfo['path']);
		}
		$parent = array('path'=>'{userFav}/');
		$fileInfo = Action('explorer.listDriver')->parsePathIO($fileInfo,$parent);
		if($fileInfo['ioDriver'] == 'Local' && $fileInfo['ioBasePath']){
			$base = rtrim($fileInfo['ioBasePath'],'/');
			if(substr($base,0,2) == './') {
				$base = substr_replace($base, BASIC_PATH, 0, 2);
			}
			return $base . '/' . ltrim($pathParse['param'], '/');
		}
		return false;
	}

    //linux 注意修改获取bin文件的权限问题;
	public function check(){
		KodUser::checkRoot();
        $bin = $this->in['soffice'];
		$plugin = Action($this->pluginName);
        if(!empty($bin)) {
            $plugin->setConfig(array('lbSoffice' => $bin));
        }
		if(isset($_GET['check'])){
			if(!function_exists('shell_exec')) {
				show_json(LNG('officeViewer.libreOffice.execDisabled'), false);
			}
			$msg = '';
            if(!$soffice = $this->getSoffice()) {
				$msg = LNG('officeViewer.libreOffice.checkError');
            }
            show_json($msg, !!$soffice);
		}
        Action($this->pluginName)->includeTpl('static/libreoffice/check.html');
	}

	// 获取soffice路径
    public function getSoffice(){
        $check = 'LibreOffice';
		$data = Action($this->pluginName)->_appConfig('lb');
        $bin = !empty($data['soffice']) ? $data['soffice'] : '/usr/local/bin/soffice';	// 默认路径为cockpit中添加
		$bin = escapeShell(iconv_system($bin));	// win路径空格处理
        $result = $this->checkBin($bin,$check);
        return $result ? $bin : false;
    }
    private function checkBin($bin,$check){
		$code = Cache::get($bin);
		if ($code) return $code;
		$result = shell_exec($bin.' --help');	// ' 2>&1'
		$code = strstr($result,$check) ? true : false;
		Cache::set($bin, $code);
		return $code;
	}
}