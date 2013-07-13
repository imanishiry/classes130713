<?php
/**
 * Database Access Class
 *
 * @auther imanishi
 */
require_once(INCLUDE_PATH . 'db_util.php');
class DBO
{
    /**
     * PDOオブジェクト
     */
    protected $db = null;

    /**
    * DBOクラスのオブジェクトを保持
    */
    private static $instance = null;

    /**
     * テーブル名
     */
    protected $tablename = '';

    /**
     * 定数
     */
    // クラス名
    const CLASSNAME                  = 'DBO';
    const CLASSNAME_SLAVE            = 'DBOSlave';

    // データベースタイプ
    const DB_TYPE                    = 'mysql';

    // 内部文字コード
    const CHARACTER_SET              = 'UTF-8';

    // 表示文字コード
    const DISPLAY_CHARACTER_SET      = 'sjis-win';

    const ORDERBY_DESC = 0;
    const ORDERBY_ASC  = 1;
    const PAGE_LIMIT   = 5;
    const PAGE_KEY     = 'pg';

    private $_result   = null; // 結果格納用
    private $_rowCount = null; // 影響行数格納用
    private $_needConv = null; // db_utilで定義される文字コードがことなれば
                               // in out 時に変換する


    /**
     * PDOオブジェクトを格納
     */
    function __construct($tablename)
    {
        // DBOインスタンスは1回しか生成しない
        if (is_null(self::$instance)) {
            // PDOオブジェクトを生成
            try {
                $dns = self::DB_TYPE . ':host=' . DB_HOST . '; dbname=' . DB_NAME;
                self::$instance = new PDO($dns, DB_USER, DB_PASS);
                $this->db = &self::$instance;
            } catch (PDOException $e){
                error_log('dberror:'.$e->getMessage());
                die;
            }
        } else {
            $this->db = &self::$instance;
        }

        // エラーをキャッチ
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // テーブル名を格納
        $this->tablename = $tablename;

        if (DEFAULT_STRING_ENCODING != DATABASE_STRING_ENCODING) {
            $this->_needConv = true;
        }
    }

    /**
     * DBオブジェクト作成
     * DBサブクラスのオブジェクトを作成。
     *
     * @param string  $name ディレクトリ_ファイル名
     * @return object DB
     */
    public static function factory($tablename = '')
    {
        $classname = self::CLASSNAME;
        $obj = new $classname($tablename);

        // テーブル名を指定しない場合はPDOオブジェクトのみ返却
        if ( '' == $tablename ) $obj = self::$instance; 

        return $obj;
    }

    /**
     * クエリ実行
     * クエリを実行し取得データを配列に格納。
     *
     * @param string  $sql 実行クエリ
     * @return array 
     */
    protected function execQuery($sql)
    {
        $stmt = $this->db->query($sql);

        return $this->_fetch($stmt);
    }

    /**
     * トランザクションの開始
     *
     * @return void
     */
    public function begin()
    {
        $this->db->beginTransaction();
    }

    /**
     * トランザクションのコミット
     *
     * @return void
     */
    public function commit()
    {
        $this->db->commit();
    }

    /**
     * トランザクションのロールバック
     *
     * @return void
     */
    public function rollBack()
    {
        $this->db->rollBack();
    }

    /**
     * コネクションを切断
     *
     * @access public
     * @return void
     */
    function disconnect()
    {
        unset($this->db);
    }

    /**
     * 検索結果を返す
     *
     * @param array $column array 表示する列名を配列でセット
     * @param array $where 検索条件(キーに列名,値に検索値)
     * @param array $orderby 配列でセット(キーに整列基準列、値に0:DESC それ以外:ASC）
     * @param int $limit リミット 
     * @param int $offset オフセット 
     * @return array 検索結果
     */
    public function getAllSearch($column = array(), $where = array(), $orderby = array(), $offset = 0, $limit = 0 ) {

        // 取得開始レコード
        $offset = $limit * $offset;

        // 検索列を生成
        $column_str = $this->_makeColumn($column);

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // limit区を生成
        $limit_str = $this->_makeLimit($offset, $limit);

        // SQL
        $sql = 'SELECT ';
        $sql .=     $column_str;
        $sql .= ' FROM ';
        $sql .=     $this->tablename;
        $sql .=     $where_str; 
        $sql .=     $order_str; 
        $sql .=     $limit_str; 

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $this->_fetch($stmt);

        } catch (PDOException $e){
            $this->disconnect();
            die;
        }
        return $result;
    }

    /**
     * 検索結果を返す(ページャー付)
     *
     * @param array $column array 表示する列名を配列でセット
     * @param array $where 検索条件(キーに列名,値に検索値)
     * @param array $orderby 配列でセット(キーに整列基準列、値に0:DESC それ以外:ASC）
     * @param int $page_now カレントページ
     * @param int $limit リミット
     * @return array 検索結果
     */
    public function getAllSearchPager($column = array(), $where = array(), $orderby = array(), $page_now = 0, $limit = self::PAGE_LIMIT) {

        if (!empty($_REQUEST[self::PAGE_KEY])) {
            $page_now = $_REQUEST[self::PAGE_KEY];
        }

        // 取得開始レコード
        $offset = $limit * $page_now;

        // 検索列を生成
        $column_str = $this->_makeColumn($column);

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // limit区を生成
        $limit_str = $this->_makeLimit($offset, $limit);

        // SQL
        $sql = 'SELECT ';
        $sql .=    ' COUNT(*) cnt';
        $sql .= ' FROM ';
        $sql .=     $this->tablename;
        $sql .=     $where_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $this->_fetch($stmt);

        } catch (PDOException $e){
            $this->disconnect();
            error_log('dberror:'.$e->getMessage());
            die;
        }

        // 全件レコード数
        $numrows = $result[0]['cnt'];

        $sql = 'SELECT ';
        $sql .=    $column_str;
        $sql .= ' FROM ';
        $sql .=     $this->tablename;
        $sql .=     $where_str;
        $sql .=     $order_str;
        $sql .=     $limit_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $this->_fetch($stmt);

        } catch (PDOException $e){
            $this->disconnect();
            error_log('dberror:'.$e->getMessage());
            die;
        }

        // ページングリンクセット
        $paging = $this->setPagingLink($page_now,$limit,$numrows);
        return array($result, $paging, $numrows);
    }

    /**
     * 検索結果を返す(1レコード)
     *
     * @param array $column 表示する列名を配列でセット
     * @param array $where 検索条件(キーに列名,値に検索値)
     * @param array $orderby 配列でセット(キーに整列基準列、値に0:DESC それ以外:ASC）
     * @return array 検索結果
     */
    public function getRow($column = array(), $where = array(), $orderby = array()) {

        // 検索列を生成
        $column_str = $this->_makeColumn($column);

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // SQL
        $sql = 'SELECT ';
        $sql .=     $column_str;
        $sql .= ' FROM ';
        $sql .=     $this->tablename;
        $sql .=     $where_str;
        $sql .=     $order_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e){
            $this->disconnect();
            error_log('dberror:'.$e->getMessage());
            die;
        }
        // 特定カラムの文字コード変換
        $this->_conv_to_display($result);

        return $result;
    }

    /**
     * 検索結果を返す(1レコード1カラム)
     *
     * @param array $column 表示する列名を文字列でセット
     * @param array $where 検索条件(キーに列名,値に検索値)
     * @param array $orderby 配列でセット(キーに整列基準列、値に0:DESC それ以外:ASC）
     * @return array 検索結果
     */
    public function getOne($column_str, $where = array(), $orderby = array()) {

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // SQL
        $sql = 'SELECT ';
        $sql .=     $column_str;
        $sql .= ' FROM ';
        $sql .=     '`'.$this->tablename.'`';
        $sql .=     $where_str;
        $sql .=     $order_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e){
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return isset($result[$column_str]) ? $this->_conv_to_str($result[$column_str]): false;
    }

    /**
     *  更新
     *
     * @param array $set
     * @param array $where
     * @return bool
     */
    public function update($set = array(), $where = array(), $orderby = array()) {

        // set区を生成
        $set_str = $this->_makeSet($set);

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // SQL
        $sql = 'UPDATE ';
        $sql .=   '`' . $this->tablename . '`';
        $sql .= ' SET ';
        $sql .=     $set_str;
        $sql .=     $where_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $set);
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

        } catch (PDOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * 更新（加減算）
     *
     * @param array $set
     * @param array $where
     * @return bool
     */
    public function updatePlus($set = array(), $where = array(), $orderby = array(), $minus_flg = 0) {

        // set区を生成
        $set_str = $this->_makeSetPlusMinus($set, $minus_flg);

        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // orderby区を生成
        $order_str = $this->_makeOrderBy($orderby);

        // SQL
        $sql = 'UPDATE ';
        $sql .=     '`' . $this->tablename . '`';
        $sql .= ' SET ';
        $sql .=     $set_str;
        $sql .=     $where_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $set);
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

        } catch (PDOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * 登録更新
     *
     * @param array $column
     * @param array $set
     * @param array $values
     * @return bool
     */
    public function init($column = array(), $values = array(), $set = array())
    {
        // 登録カラムを生成
        $column_str = $this->_makeColumn($column);
        // 登録値を生成
        $values_str = $this->_makeValues($column, $values);
        // set区を生成
        $set_str = $this->_makeSetValues($set);

        // SQL
        $sql = 'INSERT INTO ';
        $sql .=     $this->tablename;
        $sql .= '('; 
        $sql .=     $column_str;
        $sql .= ')'; 
        $sql .= ' VALUES '; 
        $sql .=     $values_str;
        $sql .= ' ON DUPLICATE KEY UPDATE '; 
        $sql .=     $set_str;
     
        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValueByValues($stmt, $column, $values);

            // 実行
            $stmt->execute();

        } catch (PDOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * 最後にINSERTしたPrimary_keyを取得
     *
     * @return 最後にINSERTしたPkey
     */
    public function getLastInsertId()
    {
        // SQL
        $sql = 'SELECT ';
        $sql .=     ' LAST_INSERT_ID() ';
        $sql .= ' FROM ';
        $sql .=     $this->tablename;

        try {
            $stmt = $this->db->prepare($sql);

            // 実行
            $stmt->execute();

            // 結果取得
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e){
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return isset($result['LAST_INSERT_ID()']) ? $result['LAST_INSERT_ID()'] : false;

    }

    /**
     * 検索行を削除
     *
     * @param $where
     * @return true
     */
    public function delete($where = array())
    {
        // 検索条件を生成
        $where_str = $this->_makeWhere($where);

        // SQL
        $sql = 'DELETE FROM ';
        $sql .=     $this->tablename;
        $sql .=     $where_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValue($stmt, $where);

            // 実行
            $stmt->execute();

        } catch (PDOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * トランケート
     *
     */
    public function truncate()
    {
        // SQL
        $sql = 'TRUNCATE TABLE ';
        $sql .=     $this->tablename;

        try {
            $stmt = $this->db->prepare($sql);

            // 実行
            $stmt->execute();

        } catch (PDOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * 登録(バルクインサート)
     *
     * @param array $column
     * @param array $values 
     * @param int   $ignore_flg 0以外でIGNOREインサート 
     * @return true
     */
    public function insert($column = array(), $values = array(), $ignore_flg = 0 )
    {
        // 登録カラムを生成
        $column_str = $this->_makeColumn($column);
        
        // 登録値を生成
        $values_str = $this->_makeValues($column, $values);

        if ( !empty($ignore_flg) ) {
            $insert = 'INSERT IGNORE INTO ';
        } else {
            $insert = 'INSERT INTO ';
        }

        // SQL
        $sql =  $insert;
        $sql .=     $this->tablename;
        $sql .= '('; 
        $sql .=     $column_str;
        $sql .= ')'; 
        $sql .= ' VALUES '; 
        $sql .=     $values_str;

        try {
            $stmt = $this->db->prepare($sql);

            // 値をバインド
            $this->_bindValueByValues($stmt, $column, $values);

            // 実行
            $ret = $stmt->execute();

        } catch (DBOException $e){
            $this->rollback();
            $this->disconnect();
            error_log($e->getMessage());
            die;
        }
        return true;
    }

    /**
     * フェッチ 
     */
    protected function _fetch($stmt) {
        $result = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
            $this->_conv_to_display($row);
            $result[] = $row; 
        }
        return $result;
    }

    private function _conv_to_display(&$row) {
        
        if (isset($row['title'])) {
            $row['title'] = $this->_conv_to_str($row['title']);
        } 
        if (isset($row['name'])) {
            $row['name'] = $this->_conv_to_str($row['name']);
        } 
        if (isset($row['detail'])) {
            $row['detail'] = $this->_conv_to_str($row['detail']);
        }
        if (isset($row['explain'])) {
            $row['explain'] = $this->_conv_to_str($row['explain']);
        }
        if (isset($row['comment'])) {
            $row['comment'] = $this->_conv_to_str($row['comment']);
        }
        if (isset($row['nickname'])) {
            $row['nickname'] = $this->_conv_to_str($row['nickname']);
        }
        if (isset($row['mes'])) {
            $row['mes'] = $this->_conv_to_str($row['mes']);
        }
        if (isset($row['group_name'])) {
            $row['group_name'] = $this->_conv_to_str($row['group_name']);
        }
    }

    public function _conv_to_str($str)
    {
        return mb_convert_encoding($str, self::DISPLAY_CHARACTER_SET, self::CHARACTER_SET);
    }

    public function _conv_to_db($str)
    {
        return mb_convert_encoding($str, self::CHARACTER_SET, self::DISPLAY_CHARACTER_SET);
    }

    /**
     * Where区生成
     */
    protected function _makeWhere($where = array()) {
        $where_str = '';
        if ( 1 <= count($where)) {
            $cnt = 0;
            foreach ($where as $key => $val) {
                if ( 0 == $cnt ) {
                    $where_str = ' WHERE ';
                } elseif ( strpos($key, '||' ) !== false ) {
                    $where_str .= ' OR ';
                    $key = str_replace('||' , '' , $key);
                } else {
                    $where_str .= ' AND ';
                }
                if (strpos($key, '|in' ) !== false) {

                    $this->_makeSign($key);

                    $where_str .= $key . ' IN (';
                    $cnt = count($val);
                    for($i = 1; $i <= $cnt; $i++) {
                        $where_str .= ':'. $key . $i;
                        if ($i != $cnt) {
                            $where_str .= ',';
                        }
                    }
                    $where_str .= ') ';
                } else {
                    $sign = $this->_makeSign($key);
                    $where_str .= $key . $sign . ':'.$key;
                }
                $cnt++;
            }
        }
        return $where_str;
    }

    /**
     * 符号を生成
     */
    private function _makeSign( &$key )
    {
        if ( false !== strpos($key, '|>=')){
            $sign = '>=';
            $key = str_replace('|>=', '', $key);
        } elseif ( false !== strpos( $key, '|>')) {
            $sign = '>';
            $key = str_replace('|>', '', $key);
        } elseif ( false !== strpos( $key, '|<>')) {
            $sign = '<>';
            $key = str_replace('|<>', '', $key);
        } elseif ( false !== strpos( $key, '|<=')) {
            $sign = '<=';
            $key = str_replace('|<=', '', $key);
        } elseif ( false !== strpos( $key, '|<')) {
            $sign = '<';
            $key = str_replace('|<', '', $key);
        } elseif ( false !== strpos( $key, '|in')) {
            $key = str_replace('|in', '', $key);
            return ;
        } elseif ( false !== strpos( $key, '||')) {
            $key = str_replace('||', '', $key);
            return ;
        } else {
            $sign = '=';
        }
        $sign = ' ' . $sign . ' ';
        return $sign;
    }

    /**
     * Set区生成
     */
    protected function _makeSet($set = array()) {
        $set_str = '';
        if ( 1 <= count($set)) {
            $cnt = 0;
            foreach ($set as $key => $val) {
                if ( 1 <= $cnt ) $set_str .= ',';
                $set_str .= $key. ' = '. ':'.$key;
                $cnt++;
            }
        }
        return $set_str;
    }

    /**
     * Set区生成(initメソッド用)
     */
    protected function _makeSetValues($set = array()) {
        $set_str = '';
        if ( 1 <= count($set)) {
            $cnt = 0;
            foreach ($set as $key => $val) {
                if ( 1 <= $cnt ) $set_str .= ',';
                $set_str .= $key. ' = '. ':'.$key. '0';
                $cnt++;
            }
        }
        return $set_str;
    }

    /**
     * Set区生成(加減算)
     *
     * @param minus_flg 1で減算、それ以外は加算
     */
    protected function _makeSetPlusMinus($set = array(), $minus_flg = 0) {

        $sign = empty($minus_flg) ? '+' : '-';
        $set_str = '';
        if ( 1 <= count($set)) {
            $cnt = 0;
            foreach ($set as $key => $val) {
                if ( 1 <= $cnt ) $set_str .= ',';
                if ( 'updated' != $key ) {
                    $set_str .= $key. ' = '. $key . $sign .' :'.$key;
                } else {
                    $set_str .= $key. ' =  :'.$key;
                }
                $cnt++;
            }
        }
        return $set_str;
    }

    /**
     * Orderby区生成
     */
    protected function _makeOrderBy($orderby = array()) {
        $order_str = '';
        if ( 1 <= count($orderby)) {
            $cnt = 0;
            $order_str .= ' ORDER BY ';
            foreach ($orderby as $key => $val) {
                if ( 0 < $cnt ) $order_str .= ', ';
                $tmp = self::ORDERBY_DESC == $val ? 'DESC' : 'ASC';
                $order_str .= $key. ' '. $tmp;
                $cnt++;
            }
        }
        return $order_str;
    }

    /**
     * Values区生成
     */
    protected function _makeValues($column = array(), $values = array()) {
        $values_str = '';
        if ( 1 <= count($values) && 1 <= count($column) ) {
            $count = 0;
            $max = count($values);
            $values_str .= '';
            foreach ($values as $key => $value_arr) {
                $suf = $key;
                $cnt = 0;
                $m = count($value_arr);
                
                //dump($value_arr);
                
                foreach ($value_arr as $k => $val) {
                    $cnt++;
                    if ( 1 == $cnt ) $values_str .= '(';
                    $values_str .= ':'.$column[$k]. $suf;
                    if ( $cnt == $m ) {
                        $values_str .= ')';
                    } else {
                        $values_str .= ',';
                    }
                }
                $count++;
                if ( $count < $max ) {
                    $values_str .= ',';
                }
            }
        }
        return $values_str;
    }

    /**
     * Values区用bindValue
     */
    protected function _bindValueByValues(&$stmt, $column, $values = array()) {
        if ( 0 <=  count($values) && 0 <= count($column) ) {
            $num = 0;
            foreach ( $values as $key => $row) { 
                foreach ( $column as $num => $column_name) {
                    $arr = array( $column_name. $key => $row[$num] );
                    $this->_bindValue($stmt, $arr);
                    $num++;
                }
            }
        }
    }

    /**
     * カラム文字列生成
     */
    protected function _makeColumn($column) {
        $columns = '';
        if ( 1 <= count($column)) {
            $columns = implode(',',$column);
        } else {
            $columns = ' * ';
        }
        return $columns;
    }

    /**
     * リミット区生成
     */
    protected function _makeLimit($offset = 0, $limit = self::PAGE_LIMIT) {
        $limit_str = '';
        if (!empty($limit)) {
            $limit_str = ' LIMIT '. $offset . ', '. $limit;
        }
        return $limit_str;
    }

    /**
     * 値をバインド
     */
    protected function _bindValue(&$stmt, $where) {
        foreach ($where as $key => $val) {
            $in = 0;
            if (is_array($val)) $in = 1; 
            $this->_makeSign($key);
            if ($in == 1) {            
                $cnt = count($val);
                for ($i=1; $i<=$cnt; $i++) {
                    $stmt->bindValue(':'.$key.$i, $this->_conv_to_db($val[$i-1]));
                }
            } else {
                $stmt->bindValue(':'.$key, $this->_conv_to_db($val));
            }
        }
    }

    /**
     * ページング
     */
    protected function setPagingLink($page_now,$limit,$numrow) {

        $prev_str = '<<前の'.$limit.'件';
        $next_str = '次の'.$limit.'件>>';

        $prev_str = mb_convert_encoding($prev_str, self::DISPLAY_CHARACTER_SET, self::CHARACTER_SET);
        $next_str = mb_convert_encoding($next_str, self::DISPLAY_CHARACTER_SET, self::CHARACTER_SET);

        $page_back = $page_now-1;
        $page_next = $page_now+1;
        $next_exist = $numrow / $limit > $page_next ? true : false;
        $link = '';

        $req = '';
        $cnt = 0;
        foreach ($_REQUEST as $key => $val) {
            if ( $key == self::PAGE_KEY ) continue;
            if ( 0 == $cnt) {
            } else {
                $req .= '&'; 
            } 
            if ( 'aid' != $key ) {
                $req .= $key . '=' . $val;
            }
            $cnt++;
        }
        $ctl_key = GET_KEY_PAGE_ID;

        $url = 'http://' . $_SERVER["SERVER_NAME"] . '/?' . $req;

        if ($page_now != 0) {
             $link = '<a href='. $url . '&' .self::PAGE_KEY.'='.$page_back.' class="motto">'.$prev_str.'</a>   ';
             if ( true === $next_exist ) {
                 $link .= '<a href='. $url . '&' . self::PAGE_KEY.'='.$page_next.' class="motto">'.$next_str.'</a>  ';
             }
         } else {
             if ( true === $next_exist ) {
                 $link = '<a href='. $url . '&' .self::PAGE_KEY.'='.$page_next.' class="motto">'.$next_str.'</a>  ';
             }
         }

         return $link;
    }

    /**
     * デストラクタ
     */
    function __destruct() 
    {
        $this->disconnect();
    }
}


