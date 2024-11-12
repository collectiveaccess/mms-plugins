<?php
/**
 * Array to Text Table Generation Class
 *
 * @author Tony Landis <tony@tonylandis.com>
 * @link http://www.tonylandis.com/
 * @copyright Copyright (C) 2006-2009 Tony Landis
 * @license http://www.opensource.org/licenses/bsd-license.php
 */
class ArrayToTextTable
{
    /** 
     * @var array The array for processing
     */
    private $rows;

    /** 
     * @var int The column width settings
     */
    private $cs = array();

    /**
     * @var int The Row lines settings
     */
    private $rs = array();

    /**
     * @var int The Column index of keys
     */
    private $keys = array();

    /**
     * @var int Max Column Height (returns)
     */
    private $mH = 2;

    /**
     * @var int Max Row Width (chars)
     */
    private $mW = 100;

    private $head  = false;
    private $pcen  = "+";
    private $prow  = "-";
    private $pcol  = "|";


    /** Prepare array into textual format
     *
     * @param array $rows The input array
     */
    public function ArrayToTextTable($rows)
    {
        // Check if $rows is an array and not empty
        if (!is_array($rows) || empty($rows)) {
            // Handle the error or set $this->rows to an empty array
            $this->rows = [];
            return false; // Or handle accordingly
        }

        $this->rows =& $rows;
        $this->cs=array();
        $this->rs=array();
 
        if(!$xc = count($this->rows)) return false; 
        $this->keys = array_keys($this->rows[0]);
        $columns = count($this->keys);
        
        for($x=0; $x<$xc; $x++)
            for($y=0; $y<$columns; $y++)    
                $this->setMax($x, $y, $this->rows[$x][$this->keys[$y]]);
    }
    
    /**
     * Show the headers using the key values of the array for the titles
     * 
     * @param bool $bool
     */
    public function showHeaders($bool)
    {
       if($bool) $this->setHeading(); 
    } 
    
    /**
     * Set the maximum width (number of characters) per column before truncating
     * 
     * @param int $maxWidth
     */
    public function setMaxWidth($maxWidth)
    {
        $this->mW = (int) $maxWidth;
    }
    
    /**
     * Set the maximum height (number of lines) per row before truncating
     * 
     * @param int $maxHeight
     */
    public function setMaxHeight($maxHeight)
    {
        $this->mH = (int) $maxHeight;
    }
    
    /**
     * Prints the data to a text table
     *
     * @return string
     */
    public function render()
    {
    	$vs_buf = '';
  
        $vs_buf = $this->printLine();
        $vs_buf .= $this->printHeading();
        
        $rc = count($this->rows);
        for($i=0; $i<$rc; $i++) {
			$vs_buf .= $this->printRow($i);
		}

		$vs_buf .= $this->printLine(false);

        return $vs_buf;
    }

    private function setHeading()
    {
        $data = array();  
        foreach($this->keys as $colKey => $value)
        { 
            $this->setMax(false, $colKey, $value);
            $data[$colKey] = strtoupper($value);
        }
        if(!is_array($data)) return false;
        $this->head = $data;
    }

    private function printLine($nl=true)
    {
    	$vs_buf = '';
        $vs_buf .= $this->pcen;
        foreach($this->cs as $key => $val)
            $vs_buf .= $this->prow .
				$this->mb_str_pad('', $val, $this->prow, STR_PAD_RIGHT) .
                $this->prow .
                $this->pcen;
        if($nl) $vs_buf .= "\n";

		return $vs_buf;
    }

    private function printHeading()
    {
    	$vs_buf = '';
        if(!is_array($this->head)) return false;

        $vs_buf .= $this->pcol;
        foreach($this->cs as $key => $val)
            $vs_buf .= ' '.
                $this->mb_str_pad($this->head[$key], $val, ' ', STR_PAD_BOTH) .
                ' ' .
                $this->pcol;

        $vs_buf .= "\n";
		$vs_buf .= $this->printLine();
		return $vs_buf;
    }

    private function printRow($rowKey)
    {
    	$vs_buf = '';
        // loop through each line
        for($line=1; $line <= $this->rs[$rowKey]; $line++)
        {
            $vs_buf .=$this->pcol;
            for($colKey=0; $colKey < count($this->keys); $colKey++)
            { 
                $vs_buf .=" ";
                $vs_buf .=$this->mb_str_pad(substr($this->rows[$rowKey][$this->keys[$colKey]], ($this->mW * ($line-1)), $this->mW), $this->cs[$colKey], ' ', STR_PAD_RIGHT);
                $vs_buf .=" " . $this->pcol;
            }  
            $vs_buf .= "\n";
        }

        return $vs_buf;
    }

    private function setMax($rowKey, $colKey, &$colVal)
    { 
        $w = mb_strlen($colVal);
        $h = 1;
        if($w > $this->mW)
        {
            $h = ceil($w % $this->mW);
            if($h > $this->mH) $h=$this->mH;
            $w = $this->mW;
        }
 
        if(!isset($this->cs[$colKey]) || $this->cs[$colKey] < $w)
            $this->cs[$colKey] = $w;

        if($rowKey !== false && (!isset($this->rs[$rowKey]) || $this->rs[$rowKey] < $h))
            $this->rs[$rowKey] = $h;
    }
	
	//mb_str_pad changed to a private class function for compatibility with Collective Access 2.0
	private function mb_str_pad($input, $pad_length, $pad_string, $pad_style, $encoding="UTF-8") 
	{
		return str_pad($input,
			strlen($input)-mb_strlen($input,$encoding)+$pad_length, $pad_string, $pad_style);
	}
}
