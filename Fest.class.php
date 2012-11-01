<?php
function fileread($filename) {
	if (!is_file($filename))
		throw new Exception('File not found');
	$f = fopen($filename, 'r');
	if (!$f)
		throw new Exception("Can`t open file '{$filename}' for reading.");
	return fread($f, filesize($filename));
}

function filewrite($filename, $data){
	if (!is_file($filename))
		throw new Exception('File not found');
	$f = fopen($filename, 'r');
	if (!$f)
		throw new Exception("Can`t open file '{$filename}' for writing.");
	return fwrite($f, $data);
}

function explodeVariablePath($path){
	$aPath = explode('/', $path);
	$name = array_pop($aPath);
	$path = implode('/', $aPath);
	return array('path'=>$path, 'name'=>$name);
}

function out($message){
	echo $message;	
}

class CFestNode {
	const FEST_TEXT 				= 0;
	const FEST_VARIABLE 			= 1;
	const FEST_BLOCK 				= 2;
	
	public $iType 					= 0;
	public $sName 					= '';
	public $iStart 					= 0;
	public $iLenght 				= 0;
	/**
	 * @var CFestBlock
	 */
	public $pParent 				= null;
	public $sValue 					= null;
	
	public $sAbsolutePath 			= '';
	
	public function __construct($iType, $iStart, $iLength, $sValue, $sName){
		// XXX: store "path" value for logging
		$this->iType 				= $iType;
		$this->iStart 				= $iStart;
		$this->iLenght 				= $iLength;
		$this->sValue 				= $sValue;
		$this->sName 				= $sName;
	}
	
	public function &getValue(){
		return $this->sValue;
	}
	
}

class CFestBlock extends CFestNode {
	/**
	 * @var CFestBlock[]
	 */
	public $aTreeNodes 				= array();
	/**
	 * Root node
	 *
	 * @var CFestRootNode
	 */
	public $pRootNode 				= null;
	
	public $pLastIteratoin 			= null;
	public $iLastIterId 			= 1;
	public $iIterated 				= 0;
	public $aIterations 			= array();
	public $aVariables 				= array();
	
	public function __construct($iType, $iStart, $iLength, $sName, $pParent){
		$this->iType 				= $iType;
		$this->iStart 				= $iStart;
		$this->iLenght 				= $iLength;
		$this->sName 				= $sName;
		$this->sValue 				= null;
		$this->pParent 				= $pParent ? $pParent : $this;
		// $this->addNode('.', $this);
	}
	
	public function &iterate(&$aPath, $i, $iParentKey){
		echo "Iterate with key: {$iParentKey} {$aPath[$i]}<br>";
		if ($i == count($aPath))
			return $this->pLastIteratoin;
		$this->iIterated++;
		// if (!isset($this->pRootNode->aOldLastIteratedNodes[$this->sAbsolutePath])) {
			$l = array_push($this->aIterations, array('variables'=>array(), 'parent_key'=>$iParentKey, 'key'=>++$this->iLastIterId));
			$this->pLastIteratoin = &$this->aIterations[$l-1];
		// } 
		/* else if ($i >= count($aPath))
			return $this->pParent->pLastIteratoin; */
		$p = $aPath[$i];
		if (!empty($p)){ 
			if ($p=='..'){
				return $this->pParent->iterate($aPath, $i+1, $this->iLastIterId);
			} 
			return $this->aTreeNodes[$p]->iterate($aPath, $i+1, $this->iLastIterId);
		}
	}
	
	public function &parse($iParentKey){
		// $iteration is used for values
		$result = '';
		$aGlobals = $this->pRootNode->aGlobals;
		foreach ($this->aIterations as &$iter){
			print_r($iter);
			echo "<br>";
			if ($iter['parent_key'] == $iParentKey){
				// We wanna iterate!
				$aVars = $iter['variables'];
				foreach ($this->aTreeNodes as &$node){
					if ($node->sName == 'variable')
					// print_r($iter);
					if ($node->iType==self::FEST_TEXT)
						$result .= $node->sValue;
					else if ($node->iType==self::FEST_VARIABLE) {
						$result .= (isset($aVars[$node->sName]) ? 
							$aVars[$node->sName] : 
								(isset($aGlobals[$node->sName]) ? 
									$aGlobals[$node->sName] : ''));
					} else if ($node->iType==self::FEST_BLOCK)
						$result .= $node->parse($iter['key']);
				}
			}
		}
		return $result;
	}
	
	public function addNode($sName, $pNode){
		if ($sName)
			$this->aTreeNodes[$sName] = $pNode;
		else 
			$this->aTreeNodes[] = $pNode;
	}

	public function &getNodeByPath($aPath, $i=0){
		if ($i == count($aPath))
			return $this;
		$p = $aPath[$i];
		if (!empty($p)){ 
			if ($p=='..'){
				return $this->pParent->getNodeByPath($aPath, $i+1);
//			} else if ($p=='.'){
//				return $this->getNodeByPath($aPath, $i+1);
			}
			return $this->aTreeNodes[$p]->getNodeByPath($aPath, $i+1);
		} else {
			return $this->getNodeByPath($aPath, $i+1);
		}
	}
	
}

class CFestRootNode extends CFestBlock {
	/**
	 * @var CFestBlock
	 */
	public $pCurrentNode 			= null;
	public $aIterations 			= array();
	public $pCurrentIteration 		= null;
	public $aGlobals 				= array();
	
	// Nodes to store last iterated (without the last one)
	// to not duplicate them!
	public $aLastIteratedNodes 		= array();
	public $aOldLastIteratedNodes 	= array();
	
	public $iLastKey 				= array();
	
	public function __construct() {
		$this->pParent 			= $this;
		$this->pCurrentNode 	= $this;
		$this->pRootNode 		= $this;
		$this->addNode('.', $this);
	}
	
	/**
	 * Get tree node by absolute or relative path
	 *
	 * @param string $sPath path to node ('/some/path', './../upper', ...);
	 * @return CFestNode $node  
	 */
	public function &getNode($sPath){
		if (empty($sPath))
			return $this->pCurrentNode;
		$aPath = explode('/', $sPath);
		if ($aPath[0] == NULL) {
			// absolute path
			return $this->getNodeByPath($aPath, 1);
		} else {
			// relative path
			return $this->pCurrentNode->getNodeByPath($aPath);
		}
	}
	
	public function iterate($sPath){
		$this->iLastIterId = 1;
		$key = $this->iLastIterId; // ++$this->iLastIterId;
		$this->aOldLastIteratedNodes = $this->aLastIteratedNodes;
		$aPath = explode('/', $sPath);
		if ($aPath[0] == NULL) {
			// absolute path
			$this->pCurrentIteration = parent::iterate($aPath, 1, $key);
			// $this->pCurrentIteration = $this->iterate($aPath, 1);
		} else {
			// relative path
			// echo "LAST ID: ".print_r($this->pCurrentNode, true). "<br>";
			$this->pCurrentIteration = $this->pCurrentNode->iterate($aPath, 0, $this->pCurrentNode->iLastIterId);
		}
	}
	
	public function &parse(){
		$result = '';
		foreach ($this->aTreeNodes as &$node){
			if ($node->iType==CFestNode::FEST_TEXT)
				$result .= $node->sValue;
			else if ($node->iType==CFestNode::FEST_VARIABLE)
				$result .= (isset($this->aGlobals[$node->sName]) ? $this->aGlobals[$node->sName] : 
					(isset($this->aVariables[$node->sName]) ? $this->aVaribles[$node->sName] : ''));
			else if ($node->iType==CFestBlock::FEST_BLOCK){
				$result .= $node->parse($this->iLastIterId);
			}
		}
		return $result;
	}
	
	public function setGlobal($name, $value){
		$this->aGlobals[$name] = $value;	
	}
	
	public function set($path, $value){
		$exp = explodeVariablePath($path);
		$name = $exp['name'];
		$node = $this->getNode($exp['path']);
		if ($node->pLastIteratoin) {
			$node->pLastIteratoin['variables'][$name] = $value;
			return true;
		}
		return false;
	}
	
	public function setCurrentNode($pNode){
		$this->pCurrentNode = $this->getNode($pNode);
	}

	public function &getCurrentNode(){
		return $this->pCurrentNode;
	}
}


class CFest {
	private $sTemplate 				= '';
	private $pCurrentNode 			= null;
	private $sFileName 				= null;
	private $sPath 					= null;
	private $sNodes 				= null;
	/**
	 * @var CFestRootNode
	 */
	private $pTreeRoot 				= null;
	
	private $aIterations 			= array();
	private $aGlobalVariables 		= array();
	
	private function getCacheName($sFileName){
		return md5($sFileName).'.fest';
	}
	
	private function parseTemplate($aParts, &$iStart=0, $sName='', &$iEnd=0){
		$aBlock = array();
		while ($iStart<count($aParts)) {
			// print_r($value);
			$value = $aParts[$iStart++];
			if (preg_match('/{[:]([^}]+)}/', $value[0], $aNames)){
				// block
				$aBlock[] = array('content'=>$this->parseTemplate($aParts, $iStart, $aNames[1], $iEnd), 
					'type'=>CFestNode::FEST_BLOCK, 'start'=>$value[1]+strlen($value[0]), 'end'=>$iEnd, 'name'=>$aNames[1]);
			} else if (preg_match('/{[\/]([^}]+)}/', $value[0], $aNames)) {
				// close block
				// TODO: check closing block name
				$iEnd = $value[1];
				return $aBlock;
			} else if (preg_match('/{\$([^}]+)}/', $value[0], $aNames)) {
				// variable 
				$aBlock[] = array('content'=>$aNames[1], 'type'=>CFestNode::FEST_VARIABLE, 'start'=>$value[1], 'end'=>$value[1]+strlen($value[0]), 'name'=>$aNames[1]);
			} else {
				// text 
				$aBlock[] = array('content'=>$value[0], 'type'=>CFestNode::FEST_TEXT, 'start'=>$value[1], 'end'=>$value[1]+strlen($value[0]), 'name'=>null);
			}
		}
		return $aBlock;
	}

	private function createFestFile(){
		// echo 'Create new file';
		$f = fopen($this->sFileName, 'r');
		$sTpl = fread($f, filesize($this->sFileName));
		preg_match_all('/({[^}]+}*|[^{]*)/', $sTpl, $aMathes, PREG_OFFSET_CAPTURE);
		$this->aNodes = $this->parseTemplate($aMathes[0]);
		$cache = array(
			'utime' => filemtime($this->sFileName),
			'nodes' => $this->aNodes
		);
		$f = fopen($this->sCache, 'w');
		fwrite($f, serialize($cache));
		fclose($f);
		chmod($this->sCache, 0777);
		return true;
	}
	
	/**
	 * Generates nodes tree
	 *
	 * @param array $aNode
	 * @param CFestBlock $pParent
	 */
	private function generateTree(&$aNode, &$pParent){
		foreach ($aNode as $node){
			if ($node['type'] == CFestNode::FEST_BLOCK){
				$pTreeNode = new CFestBlock($node['type'], $node['start'], $node['end'] - $node['start'], $node['name'], $pParent);
				$pTreeNode->sAbsolutePath = $pParent->sAbsolutePath . '/' . $node['name'];
				$pTreeNode->pRootNode = &$this->pTreeRoot;
				$this->generateTree($node['content'], $pTreeNode);
			} else {
				$pTreeNode = new CFestNode($node['type'], $node['start'], $node['end'] - $node['start'], $node['content'], $node['name']);
				$pTreeNode->sAbsolutePath = $pParent->sAbsolutePath . '/' . $node['name'];
			}
			$pParent->addNode($node['name'], $pTreeNode);
		}
	}
	
	public function __construct($sFileName, $sPath='.fest_cache') {
		// getting nodes tree (rfom file, or generate new)
		$this->sFileName = $sFileName;
		$this->sCache = "{$sPath}/".$this->getCacheName($sFileName);
		$sPath = ini_get('fest_directory') ? ini_get('fest_directory') : $sPath;
		$data = array();
		if (file_exists($this->sCache)) {
			$data = unserialize(fileread($this->sCache));
		}
		if (isset($data['utime']) && filemtime($sFileName) == $data['utime']){
			// echo 'Open existing file';
			$f = fopen($this->sCache, 'r');
			$sTpl = fread($f, filesize($this->sCache));
			fclose($f);
			$this->aNodes = unserialize($sTpl);
			$this->aNodes = $this->aNodes['nodes'];
			unset($sTpl);
		} else {
			$this->createFestFile();
		}
		// Nodes are loaded and ready for parsing!
		$this->pTreeRoot = new CFestRootNode();
		$this->generateTree($this->aNodes, $this->pTreeRoot);
		// print_r($this->pTreeRoot->getNode('/block/inner_block/../'));
	}
	
	public function iterate($sPath) {
		$this->pTreeRoot->iterate($sPath);
	}
	
	public function context($sPath) {
		// iterate nodes, and remember it parents
		$this->pTreeRoot->setCurrentNode($sPath);
	}
	
	public function setGlobal($name, $value) {
		// set variable value global
		$this->pTreeRoot->setGlobal($name, $value);
	}
	
	public function set($name, $value) {
		$this->pTreeRoot->set($name, $value);
	}
	
	public function &parse(){
		return $this->pTreeRoot->parse();
		// print_r($this->pTreeRoot);
		$result = '';
		foreach ($this->aIterations as $iter){
			if ($iter['block'])
				$result .= $iter['block']->parse($iter['variables'], $this->aGlobalVariables);
		}
		return $result;
	}
	
}
