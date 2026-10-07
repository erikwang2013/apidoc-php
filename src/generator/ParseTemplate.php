<?php
declare(strict_types = 1);

namespace erikwang2013\apidoc\generator;
use erikwang2013\apidoc\utils\DirAndFile;
use erikwang2013\apidoc\utils\Helper;

class ParseTemplate
{

    public function compile($path,$params)
    {
        $filePath    =  $path;
        $tplContent = DirAndFile::getFileContent($filePath);
        $tplContent = $this->replaceForeach($tplContent,$params);
        $tplContent = $this->replaceParams($tplContent,$params);
        $tplContent = $this->replaceIf($tplContent,$params);
        $tplContent = preg_replace("/\s+\r\n/is", "\r\n", $tplContent);
        return $tplContent;
    }

    /**
     * 替换变量
     * @param $tplContent
     * @param $params
     * @return array|string|string[]|null
     */
    protected function replaceParams($tplContent,$params){
        $key = '{$%%}';
        $pattern = '#' . str_replace('%%', '(.+?)' , preg_quote($key, '#')) . '#';
        $tplContent = preg_replace_callback($pattern, function ($matches)use ($params){
            $k = $matches[1];
            if (strpos($k, '(') !== false){
                $tagArr = explode("(", $k);
                $fun = $tagArr[0];
                $k = str_replace(")", "",$tagArr[1] );
                $v = $this->getObjectValueByKeys($params,$k);
                if (empty($v)){
                    return "";
                }
                if ($fun === "lower"){
                   return Helper::lower($v);
                }else if ($fun === "snake"){
                    return Helper::snake($v);
                }else if ($fun === "lcfirst"){
                    return lcfirst($v);
                }else if ($fun === "ucfirst"){
                    return ucfirst($v);
                }else if ($fun === "count"){
                    return count($v);
                }
            }
            $value = $this->getObjectValueByKeys($params,$k);
            if (is_bool($value)){
               return $value==true?'true':'false';
            }else if (is_array($value)){
               return $k;
            }
            return $value;
        }, $tplContent);
        return $tplContent;
    }

    /**
     * 替换if内容
     * @param $tplContent
     * @param $params
     * @return array|mixed|string|string[]
     * @throws \Exception
     */
    protected function replaceIf($tplContent,$params){
        $res = [];
        $label = "if";
        $labelList = $this->parseLabel($tplContent,$label);
        if (!empty($labelList) && count($labelList)>0){
            foreach ($labelList as $item) {
                $itemStr =$item;
                $ifChildren= $this->parseLabel($itemStr,$label,"children");

                if (!empty($ifChildren) && count($ifChildren)>0){
                    foreach ($ifChildren as $ifChild){
                        $itemChildrenContent= $this->getIfContent($ifChild);
                        $itemStr = str_replace($ifChild, $itemChildrenContent,$itemStr );
                    }
               }
                $itemContent= $this->getIfContent($itemStr);
                $tplContent =   str_replace($item, $itemContent,$tplContent );
            }
        }

        return $tplContent;
    }
    protected function parseForeach($str,$params){
        if (preg_match('#{foreach (.+?) as (.+?)=>(.+?)}#s', $str, $matches)){
            $complete = $matches[0];
            $condition = $matches[1];
            $keyField = str_replace("$", "",$matches[2] );
            $itemField = str_replace("$", "",$matches[3] );
            $conditionKey = str_replace("$", "",$condition );
            $forListData = $this->getObjectValueByKeys($params,$conditionKey);
            $contentStr = str_replace($complete, "",$str);
            $contentStr = substr($contentStr,0,-10);
            return [
                'list'=>$forListData,
                'keyField'=>$keyField,
                'itemField'=>$itemField,
                'content'=>$contentStr
            ];
        }
        return [];
    }

    /**
     * 获取所有foreach标签
     * @param $str
     * @return array
     * @throws \Exception
     */
    protected function getAllForeachLabel($str){
        $tree = [];
        $label = "foreach";
        $labelList = $this->parseLabel($str,$label);
        if (!empty($labelList) && count($labelList)>0){
            foreach ($labelList as $itemLabel) {
                $labelChildrenList = $this->parseLabel($itemLabel,$label,"children");
                if (!empty($labelChildrenList) && count($labelChildrenList)>0){
                    $childrenList = [];
                    foreach ($labelChildrenList as $item) {
                        $childrenList[]=[
                            'str'=>$item,
                            'children' => []
                        ];
                    }
                    $tree[]=[
                        'str'=>$itemLabel,
                        'children' => $childrenList
                    ];
                }else{
                    $tree[]=[
                        'str'=>$itemLabel,
                        'children' => []
                    ];
                }
            }
        }
        return $tree;
    }
    // 解析foreach
    protected function replaceForeach($html,$params,$level=""){
        $allLabelData= $this->getAllForeachLabel($html);
        $res = [];
        if (count($allLabelData)>0){
            // 遍历每个foreach标签
            foreach ($allLabelData as $labelItem) {
                $itemStr = $labelItem['str'];
                $forOption = $this->parseForeach($labelItem['str'],$params);
                $itemContent="";
                if (!empty($forOption['list']) && count($forOption['list'])>0){
                    // 处理每行数据
                    foreach ($forOption['list'] as $rowKey=>$row) {
                        $rowData = [$forOption['itemField']=>$row,$forOption['keyField']=>$rowKey];
                        $rowParams = array_merge($params,$rowData);
                        // 存在子标签，处理子标签
                        if (!empty($labelItem['children']) && count($labelItem['children'])>0){
                            $itemStrContent = "";
                            foreach ($labelItem['children'] as $childLabel){
                                $childContents = "";
                                $childStr = $childLabel['str'];
                                $childDataList = $this->parseForeach($childLabel['str'],$rowParams);
                                // 处理子标签数据
                                if (!empty($childDataList['list']) && count($childDataList['list'])>0){
                                    foreach ($childDataList['list'] as $childDataKey=>$childDataItem) {
                                        // 子标签每行数据
                                        $childDataItemData = [$childDataList['itemField']=>$childDataItem,$childDataList['keyField']=>$childDataKey,];
                                        $contentsStr= $this->getForContent($childDataList['content'],array_merge($rowParams,$childDataItemData));
                                        $contentsStr =ltrim($contentsStr,"\r\n");
                                        if (!empty(Helper::trimEmpty($contentsStr))){
                                            $childContents.= $contentsStr;
                                        }
                                    }
                                }
                                $itemStrContent.= str_replace($childLabel['str'], $childContents,$forOption['content']);
                            }
                            $rowContent=$this->replaceParams($itemStrContent,$rowParams);
                            $itemContentStr=$this->replaceIf($rowContent,$rowParams);
                            if (!empty(Helper::trimEmpty($itemContentStr))){
                                $itemContent.= $itemContentStr;
                            }
                        }else{
                            $rowContent=$this->getForContent($forOption['content'],$rowParams);
                            if (empty(Helper::trimEmpty($rowContent))){
                                $rowContent= "";
                            }
                            $itemContent.= $rowContent;
                        }
                        $itemContent =trim($itemContent,"\r\n");
                    }
                }
                $html = str_replace($labelItem['str'], $itemContent,$html );
            }
        }
        return $html;

    }

    /**
     * 获取foreach内容
     * @param $str
     * @param $params
     * @return array|mixed|string|string[]
     * @throws \Exception
     */
    protected function getForContent($str,$params){
            $content = $str;
            if (!empty($params)){
                $content = $this->replaceParams($content,$params);
                $content = $this->replaceIf($content,$params);
            }
            return $content;
    }


    /**
     * 获取if条件的内容
     * @param $str
     * @return mixed|string
     */
    protected function getIfContent($str){
        if (preg_match('#{if (.+?)}(.*?){/if}#s', $str, $matches)){
            if ($this->evaluateCondition($matches[1])){
                // 条件成立
               return $matches[2];
            }
        }
        return "";
    }

    /**
     * 计算if条件(受限求值器，不执行任何PHP代码)
     * 支持 A == B / A != B / A > B / A < B / A >= B / A <= B 与单值真值判断，以及 && / || 复合条件，
     * 两边为去引号后的字面量，均为数字时按数字比较，否则按字符串比较；
     * 单值条件中 ""、"0"、"false"、"null" 为假，其余为真；
     * 无法解析的表达式一律按假处理
     * @param string $expression
     * @return bool
     */
    protected function evaluateCondition($expression){
        $expression = trim((string)$expression);
        // 空条件或含函数调用/变量/数组等无法安全解析的字符，按假处理
        if ($expression === "" || preg_match('#[(){}\[\]$;`]#', $expression)){
            return false;
        }
        // 复合条件: 先按 || 拆分(或)，各分支再按 && 拆分(与)，优先级与 PHP 一致
        // (引号内的 && / || 会被一并拆分，属已知边界)
        if (strpos($expression, '||') !== false){
            foreach (explode('||', $expression) as $part){
                if ($this->evaluateCondition($part)){
                    return true;
                }
            }
            return false;
        }
        if (strpos($expression, '&&') !== false){
            foreach (explode('&&', $expression) as $part){
                if (!$this->evaluateCondition($part)){
                    return false;
                }
            }
            return true;
        }
        // 剩余的单个 & / | (含位运算写法)无法解析，按假处理
        if (preg_match('#[&|]#', $expression)){
            return false;
        }
        // 二元比较，运算符按长度优先匹配
        if (preg_match('#^(.*?)(===|!==|==|!=|>=|<=|>|<)(.*)$#s', $expression, $matches)){
            $left = $this->parseConditionValue($matches[1]);
            $right = $this->parseConditionValue($matches[3]);
            if (is_numeric($left) && is_numeric($right)){
                $left = $left + 0;
                $right = $right + 0;
            }
            $operator = $matches[2];
            if ($operator === '==='){
                $operator = '==';
            }else if ($operator === '!=='){
                $operator = '!=';
            }
            if ($operator === '=='){
                return $left == $right;
            }
            if ($operator === '!='){
                return $left != $right;
            }
            if ($operator === '>'){
                return $left > $right;
            }
            if ($operator === '<'){
                return $left < $right;
            }
            if ($operator === '>='){
                return $left >= $right;
            }
            return $left <= $right;
        }
        // 前置取反，如 !xxx(优先级与PHP一致，放在二元比较之后)
        if (strpos($expression, '!') === 0){
            return !$this->evaluateCondition(substr($expression, 1));
        }
        // 未加引号的多词表达式(如 a and b)无法解析，按假处理
        $firstChar = $expression[0];
        if ($firstChar !== '"' && $firstChar !== "'" && preg_match('#\s#', $expression)){
            return false;
        }
        // 单值真值判断
        return !in_array(strtolower($this->parseConditionValue($expression)), ['', '0', 'false', 'null'], true);
    }

    /**
     * 解析条件中的字面量：去掉两端成对的引号
     * @param string $value
     * @return string
     */
    protected function parseConditionValue($value){
        $value = trim((string)$value);
        $length = strlen($value);
        if ($length >= 2){
            $firstChar = $value[0];
            if (($firstChar === '"' || $firstChar === "'") && substr($value, -1) === $firstChar){
                $value = substr($value, 1, $length - 2);
            }
        }
        return $value;
    }


    /**
     * 解析指定标签
     * @param $str
     * @param $label
     * @param string $level
     * @return array
     * @throws \Exception
     */
    protected function parseLabel($str,$label,$level=""){
        // 后面的 flag 表示记录偏移量
        preg_match_all('!({/?'.$label.' ?}?)!', $str, $matches, PREG_OFFSET_CAPTURE);
        // 用数组来模拟栈
        $stack  = [];
        $top    = null;
        $result = [];
        foreach ($matches[0] as $k=>[$match, $offset]) {
            // 当取标签内容时，排除第一个和最后一个标签
            if ($level === 'children' && ($k==0 || $k>=count($matches[0])-1)){
                continue;
            }
            // 判断匹配到的如果是 开始标签
            if ($match === '{'.$label.' ') {
                $stack[] = $offset;
                // 记录开始的位置
                if ($top === null) {
                    $top = $offset;
                }
                // 如果不是
            } else {
                // 从栈底部拿一个出来
                $pop = array_pop($stack);
                // 如果取出来的是 null 就说明存在多余的 标签
                if ($pop === null) {
                    throw new \Exception('语法错误，存在多余的 {/'.$label.'} 标签');
                }
                // 如果取完后栈空了
                if (empty($stack)) {
                    // offset 是匹配到的开始标签(前面)位置，加上内容的长度
                    $newOffset = $offset + strlen($match)-$top;
                    // 从顶部到当前的偏移就是这个标签里的内容
                    $result[] = substr($str, $top, $newOffset);
                    // 重置 top 开始下一轮
                    $top = null;
                }
            }
        }
        // 如果运行完了，栈里面还有东西，那就说明缺少闭合标签。
        if (!empty($stack)) {
            throw new \Exception('语法错误，存在未闭合的 {/'.$label.'} 标签');
        }
        return $result;
    }

    /**
     * 根据keys获取对象中的值
     * @param $array
     * @param $keyStr
     * @param string $delimiter
     * @return mixed|null
     */
    public function getObjectValueByKeys($array, $keyStr, $delimiter = '.')
    {
        $keys = explode($delimiter, $keyStr);
        if (preg_match_all('#\[(.+?)]#s', $keyStr, $matches)){
            $value = $array;
            if (!empty($matches[1])){
                $matchesIndex=0;
                foreach ($keys as $keyItem) {
                    if (strpos($keyItem, '[') !== false) {
                        $tagArr = explode("[", $keyItem);
                        if (!empty($value[$tagArr[0]]) && !empty($value[$tagArr[0]][$matches[1][$matchesIndex]])){
                            $value =$value[$tagArr[0]][$matches[1][$matchesIndex]];
                        }else{
                            $value =null;
                            break;
                        }
                        $matchesIndex=$matchesIndex+1;
                    }else{
                        $value =$value[$keyItem];
                    }
                }
            }
            return $value;
        }else if (sizeof($keys) > 1) {
            $value = $array;
            foreach ($keys as $key){
                if (!empty($value[$key])){
                    $value = $value[$key];
                }else{
                    $value =null;
                    break;
                }
            }
            return $value;
        } else {
            return $array[$keyStr] ?? null;
        }
    }


}