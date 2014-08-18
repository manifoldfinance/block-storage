<?php
/**
 * Base abstract class for block storage testing. This class implements base  
 * testing logic and is extended by each of the 8 specific block storage 
 * tests. It also contains some static utility methods
 */
ini_set('memory_limit', '512m');
$block_storage_test_start_time = time();
$block_storage_test_start = microtime(TRUE);
date_default_timezone_set('UTC');

abstract class BlockStorageTest {
  
  /**
   * fio test file name for volume based test targets
   */
  const BLOCK_STORAGE_TEST_FILE_NAME = 'fio-test';
  
  /**
   * free space buffer to use for volume type test targets
   */
  const BLOCK_STORAGE_TEST_FREE_SPACE_BUFFER = 100;
  
  /**
   * true if targets are devices
   */
  protected $deviceTargets = FALSE;
  
  /**
   * graph colors array
   */
  protected $graphColors = array();
  
  /**
   * used to record fio test results. the key in this array is the test 
   * sequence name and the value is an ordered array of fio results
   */
  protected $fio = array();
  
  /**
   * run options for test implementations
   */
  protected $options = NULL;
  
  /**
   * stores purge methods used during testing. indexed by target with values
   * secureerase, trim or zero (if target not present, not purged)
   */
  private $purgeMethods = array();
  
  /**
   * used to store steady state data
   */
  protected $ssData = array();
  
  /**
   * used to store sub-tests used for report generation
   */
  protected $subtests = array();
  
  /**
   * the test identifier for the instantiated controller
   */
  protected $test;
  
  /**
   * used for determining whether or not to render verbose output
   */
  protected $verbose = FALSE;
  
  /**
   * true if targets are volumes
   */
  protected $volumeTargets = FALSE;
  
  /**
   * the workload dependent preconditioning status. one of the following:
   *   TRUE:  successful
   *   FALSE: successful, but steady state not achieved
   *   NULL:  not successful
   */
  protected $wdpc = NULL;
  
  /**
   * the termination interval for workload dependent preconditioning (i.e. the 
   * X value when steady state was achieved)
   */
  public $wdpcComplete;
  
  /**
   * the number of tests per X intervals during workload dependent 
   * preconditioning
   */
  public $wdpcIntervals;
  
  /**
   * set to TRUE by the wipc method if workload independent preconditioning is
   * successful
   */
  protected $wipc = FALSE;
  
  
  
  /**
   * removes any skip_blocksize arguments from $blocksizes
   * @param array $blocksizes the blocksizes to filter
   * @return array
   */
  protected function filterBlocksizes($blocksizes) {
    $nblocksizes = array();
    foreach($blocksizes as $bs) {
      if (!isset($this->options['skip_blocksize']) || !in_array($bs, $this->options['skip_blocksize'])) $nblocksizes[] = $bs;
    }
    return $nblocksizes;
  }
  
  
  /**
   * removes any skip_workload arguments from $workloads
   * @param array $workloads the workloads to filter
   * @return array
   */
  protected function filterWorkloads($workloads) {
    $nworkloads = array();
    foreach($workloads as $rw) {
      if (!isset($this->options['skip_workload']) || !in_array($rw, $this->options['skip_workload'])) $nworkloads[] = $rw;
    }
    return $nworkloads;
  }
  
  /**
   * Runs fio based on the runtime parameters and $options specified. If 
   * successful, returns TRUE on success, FALSE otherwise. fio execution 
   * results will be stored in the $fio instance variable (indexed by $step)
   * @param array $options additional fio options (used in addition to the 
   * default options)
   * @param string $step the identifier of the current test step (e.g. 
   * precondition)
   * @param boolean $concurrent whether or not to invoke fio concurrently 
   * on all targets or sequentially, one at a time
   * @param string $tareget specific target to use (otherwise all targets will
   * be assumed)
   * @return boolean
   */
  protected function fio($options, $step, $target=NULL, $concurrent=TRUE) {
    $success = FALSE;
    $targets = $target ? array($target) : $this->options['target'];
    
    // sequential execution
    if (!$concurrent && count($targets) > 1) {
      $success = TRUE;
      BlockStorageTest::printMsg(sprintf('Starting sequential fio execution for %d targets and step %s', count($targets), $step), $this->verbose, __FILE__, __LINE__);
      foreach($targets as $target) {
        if (!$this->fio($options, $step, $target, FALSE)) {
          $success = FALSE;
          break;
        }
      }
    }
    else if ($targets) {
      $cmd = $this->options['fio'];
      $options = array_merge($this->options['fio_options'], $options);
      if (!isset($options['numjobs'])) {
        $options['numjobs'] = count($targets) * $this->options['threads'];
      }
      if (!isset($options['iodepth'])) $options['iodepth'] = $this->options['oio_per_thread'];
      if (!isset($options['filename'])) {
        $filename = '';
        foreach($targets as $target) $filename .= ($filename ? ':' : '') . (BlockStorageTest::getDevice($target) == $target ? $target : $target . '/'. BlockStorageTest::BLOCK_STORAGE_TEST_FILE_NAME);
        $options['filename'] = $filename; 
      }
      $options['group_reporting'] = FALSE;
      $options['output-format'] = 'json';
      if (!isset($options['name'])) $options['name'] = sprintf('%s-%d', $step, isset($this->fio[$step]) ? count($this->fio[$step]) + 1 : 1);
      // determine size
      if (!isset($options['size'])) {
        // for devices use relative size
        if ($this->deviceTargets) $options['size'] = $this->options['active_range'] . '%';
        // for volumes use fixed size (total free space - )
        else {
          $size = NULL;
          foreach($targets as $target) {
            $free = $this->getFreeSpace($target);
            if ($size === NULL || $free < $size) $size = $free;
          }
          // reduce size according to active range (if < 100%) or free space buffer
          if ($this->options['active_range'] < 100) $size *= ($this->options['active_range'] * 0.01);
          else $size -= BlockStorageTest::BLOCK_STORAGE_TEST_FREE_SPACE_BUFFER;
          $size = round($size);
          // not enough free space to continue
          if ($size < 1) {
            BlockStorageTest::printMsg(sprintf('Unable to initiate fio testing for volume targets because there is insufficient free space'), $this->verbose, __FILE__, __LINE__, TRUE);
            return FALSE;
          }
          else {
            BlockStorageTest::printMsg(sprintf('Testing volume type targets using size %d MB', $size), $this->verbose, __FILE__, __LINE__);
            $options['size'] = $size . 'm';
            // register shutdown method so test files are deleted
            foreach($targets as $target) {
              if (!file_exists($file = sprintf('%s/%s', $target, BlockStorageTest::BLOCK_STORAGE_TEST_FILE_NAME))) register_shutdown_function('unlink', $file);
            }
          }
        }
      }
      foreach($options as $opt => $val) $cmd .= sprintf(' --%s%s', $opt, $val !== FALSE && $val !== NULL ? '=' . $val : '');
      BlockStorageTest::printMsg(sprintf('Starting fio using command: %s', $cmd), $this->verbose, __FILE__, __LINE__);
      if ($result = json_decode(trim(shell_exec($cmd . ' 2>/dev/null')), TRUE)) {
        $iops = NULL;
        if ($success = isset($result['jobs'][0]['error']) && !$result['jobs'][0]['error']) {
          $iops = (isset($result['jobs'][0]['read']['iops']) ? $result['jobs'][0]['read']['iops'] : 0) + (isset($result['jobs'][0]['write']['iops']) ? $result['jobs'][0]['write']['iops'] : 0);
          $mbps = (isset($result['jobs'][0]['read']['bw']) ? $result['jobs'][0]['read']['bw'] : 0) + (isset($result['jobs'][0]['write']['bw']) ? $result['jobs'][0]['write']['bw'] : 0);
          $mbps = round($mbps/1024, 2);
          if (!isset($this->fio[$step])) $this->fio[$step] = array();
          $this->fio[$step][] = $result;
          BlockStorageTest::printMsg(sprintf('fio execution successful for step %s with %d IOPS (%s MB/s). There are now %d results for this step', $step, $iops, $mbps, count($this->fio[$step])), $this->verbose, __FILE__, __LINE__);
        }
        else BlockStorageTest::printMsg(sprintf('fio execution failed with an error for step %s', $step), $this->verbose, __FILE__, __LINE__, TRUE);
      }
      else BlockStorageTest::printMsg(sprintf('fio execution failed for step %s', $step), $this->verbose, __FILE__, __LINE__, TRUE);
    }
    return $success;
  }
  
  /**
   * generates a 3d chart based on the parameters provided. return value is 
   * javascript and html that will render the chart. returns NULL on error
   * @param string $section the section identifier for the chart
   * @param array $series highcharts compatible array of data series
   * @param array $settings optional array containing Highcharts.Chart settings
   * @param string $zAxisTitle optional title for the z axis (displayed above 
   * the legend on the right side)
   * @return string
   */
  protected final function generate3dChart($section, $series, $settings=array(), $zAxisTitle=NULL) {
    $chart = NULL;
    if (isset($this->options['no3dcharts'])) $chart = '3D charts disabled - see preceding tabular data';
    else if ($section && is_array($series) && count($series) && is_array($settings)) {
      // assign series colors
      if (!isset($settings['colors'])) $settings['colors'] = $this->getGraphColors();
      if (!isset($settings['series'])) $settings['series'] = $series;
      // assign other highchart graph settings
      $stacks = array();
      foreach(array_keys($settings['series']) as $i) {
        if (isset($settings['series'][$i]['stack']) && !in_array($settings['series'][$i]['stack'], $stacks)) $stacks[] = $settings['series'][$i]['stack'];
      }
      if (!isset($settings['chart'])) $settings['chart'] = array();
      $settings['chart']['type'] = 'column';
      if (!isset($settings['chart']['options3d'])) $settings['chart']['options3d'] = array();
      $settings['chart']['options3d']['enabled'] = TRUE;
      if (!isset($settings['plotOptions'])) $settings['plotOptions'] = array();
      if (!isset($settings['plotOptions']['column'])) $settings['plotOptions']['column'] = array();
      if (!isset($settings['plotOptions']['column']['depth'])) $settings['plotOptions']['column']['depth'] = 35;
      $settings['plotOptions']['column']['stacking'] = TRUE;
      $settings['plotOptions']['column']['grouping'] = FALSE;
      if (!isset($settings['plotOptions']['series'])) $settings['plotOptions']['series'] = array();
      $settings['plotOptions']['series']['animation'] = FALSE;
      if (!isset($settings['plotOptions']['column']['groupZPadding'])) $settings['plotOptions']['column']['groupZPadding'] = 15;
      if (!isset($settings['chart']['options3d']['alpha'])) $settings['chart']['options3d']['alpha'] = 20;
      if (!isset($settings['chart']['options3d']['beta'])) $settings['chart']['options3d']['beta'] = 20;
      if (!isset($settings['chart']['options3d']['depth'])) $settings['chart']['options3d']['depth'] = ($settings['plotOptions']['column']['depth'] + $settings['plotOptions']['column']['groupZPadding'])*count($stacks);
      $settings['chart']['marginTop'] = ($settings['chart']['options3d']['alpha']*count($stacks) - 10);
      if (!isset($settings['title'])) $settings['title'] = array();
      if (!isset($settings['title']['text'])) $settings['title']['text'] = '';
      if (!isset($settings['legend'])) $settings['legend'] = array();
      if (!isset($settings['legend']['align'])) $settings['legend']['align'] = 'right';
      if (!isset($settings['legend']['layout'])) $settings['legend']['layout'] = 'vertical';
      if (!isset($settings['legend']['verticalAlign'])) $settings['legend']['verticalAlign'] = 'top';
      if ($zAxisTitle && !isset($settings['legend']['title'])) $settings['legend']['title'] = array('text' => $zAxisTitle);
      if (!isset($settings['legend']['itemMarginTop'])) $settings['legend']['itemMarginTop'] = 5;
      if (!isset($settings['legend']['reversed'])) $settings['legend']['reversed'] = TRUE;
      if (!isset($settings['credits'])) $settings['credits'] = array();
      if (!isset($settings['credits']['enabled'])) $settings['credits']['enabled'] = FALSE; 
      $chart = sprintf("<figure id=\"%s-%s\"></figure>\n", $this->test, $section);
      $chart .= sprintf('<script>$("#%s-%s").highcharts(%s);</script>', $this->test, $section, json_encode($settings));      
    }
    return $chart;
  }
  
  /**
   * generates and zips fio JSON output files. Returns TRUE on success, FALSE 
   * otherwise
   * @param string $dir optional directory where archive should be generated 
   * in. If not specified, --output will be used
   * @param string $suffix optional file name suffix
   * @return boolean
   */
  public function generateJson($dir=NULL, $suffix=NULL) {
    if (isset($this->options['nojson']) && $this->options['nojson']) return FALSE;
    
    $generated = FALSE;
    if (!$dir) $dir = $this->options['output'];
    if (is_dir($dir) && is_writable($dir) && count($this->fio) && $this->wdpcComplete && $this->wdpcIntervals) {
      $ssStart = isset($this->fio['wdpc']) ? ($this->wdpcComplete - 5)*$this->wdpcIntervals : NULL;
      BlockStorageTest::printMsg(sprintf('Generating %s JSON output files in directory %s using steady state start index %d', $this->test, $dir, $ssStart), $this->verbose, __FILE__, __LINE__);
      
      $json = array();
      foreach($this->fio as $step => $jobs) {
        foreach($jobs as $i => $job) {
          if (isset($job['jobs'][0]['jobname'])) {
            $ssmw = $step == 'wdpc' && $i >= $ssStart;
            $name = sprintf('%s%s', $job['jobs'][0]['jobname'], $ssmw ? '-ssmw' : '');
            BlockStorageTest::printMsg(sprintf('Added %s job %s to JSON output', $this->test, $name), $this->verbose, __FILE__, __LINE__);
            $job['jobs'][0]['jobname'] = $name;
            if (!$json) $json = $job;
            else $json['jobs'][] = $job['jobs'][0];
          }
        }
      }
      if ($json) {
        $file = sprintf('%s/fio-%s%s.json', $dir, $this->test, $suffix ? '-' . $suffix : '');
        if ($fp = fopen($file, 'w')) {
          fwrite($fp, json_encode($json));
          fclose($fp);
        }
        else BlockStorageTest::printMsg(sprintf('Unable to generate %s JSON output - cannot open file %s', $this->test, $file), $this->verbose, __FILE__, __LINE__, TRUE);
      }
      else BlockStorageTest::printMsg(sprintf('Unable to generate %s JSON output - no jobs', $this->test), $this->verbose, __FILE__, __LINE__, TRUE);
    }
    else BlockStorageTest::printMsg(sprintf('Unable to generate %s JSON output in directory %s. fio steps: %d; wdpcComplete=%d; wdpcIntervals=%d', $this->test, $dir, count($this->fio), $this->wdpcComplete, $this->wdpcIntervals), $this->verbose, __FILE__, __LINE__, TRUE);
    
    return $generated;
  }
  
  /**
   * generates a line chart based on the parameters provided. return value is 
   * the name of the image which may in turn be used in an image element for 
   * a content section. returns NULL on error
   * @param string $dir the directory where the line chart should be generated
   * @param string $section the section identifier for the line chart
   * @param array $coords either a single array of tuples representing the x/y
   * values, or a hash or tuple arrays indexed by the name of each set of data
   * points. coordinates should have the same 
   * @param string $xlabel optional x label
   * @param string $ylabel optional y label
   * @param string $title optional graph title
   * @param array $settings optional array of custom gnuplot settings. the 
   * following special settings are supported:
   *   height: the graph height
   *   lines:     optional line styles (indexed by line #)
   *   nogrid:    don't add y axis grid lines
   *   xLogscale: use logscale for the x axis
   *   xMin:      min value for the x axis tics - may be a percentage relative to 
   *              the lowest value
   *   xMax:      max value for the x axis tics - may be a percentage relative to 
   *              the highest value
   *   xTics:     the number of x tics to show (default 8)
   *   yLogscale: use logscale for the y axis
   *   yMin:      min value for the x axis tics - may be a percentage relative to 
   *              the lowest value
   *   yMax:      max value for the y axis tics - may be a percentage relative to 
   *              the highest value
   *   yTics:     the number of y tics to show (default 8)
   * 
   * xMin, xMax, yMin and yMax all default to the same value as the other for 
   * percentages and 15% otherwise if only 1 is set for a given 
   * axis. If neither are specified, gnuplot will auto assign the tics. If xMin
   * or xMax are specified, but not xTics, xTics defaults to 8
   * @param boolean $html whether or not to return the html <img element or just
   * the name of the file
   * @param boolean $bar whether or not to render the chart as a bar chart
   * @return string
   */
  protected final function generateLineChart($dir, $section, $coords, $xlabel=NULL, $ylabel=NULL, $title=NULL, $settings=NULL, $html=TRUE, $bar=FALSE) {
    BlockStorageTest::printMsg(sprintf('Generating line chart in %s for test %s and section %s with %d coords', $dir, $this->test, $section, count($coords)), $this->verbose, __FILE__, __LINE__);
    
    $chart = NULL;
    $script = sprintf('%s/%s-%s.pg', $dir, $this->test, $section);
    $dfile = sprintf('%s/%s-%s.dat', $dir, $this->test, $section);
    if (is_array($coords) && ($fp = fopen($script, 'w')) && ($df = fopen($dfile, 'w'))) {
      $colors = $this->getGraphColors();
      
      // just one array of tuples
      if (isset($coords[0])) $coords[''] = array($coords);
      
      // determine max points/write data file header
      $maxPoints = NULL;
      foreach(array_keys($coords) as $i => $key) {
        if ($maxPoints === NULL || count($coords[$key]) > $maxPoints) $maxPoints = count($coords[$key]);
        fwrite($df, sprintf("%s%s%s\t%s%s", $i > 0 ? "\t" : '', $key ? $key . ' ' : '', $xlabel ? $xlabel : 'X', $key ? $key . ' ' : '', $ylabel ? $ylabel : 'Y'));
      }
      fwrite($df, "\n");
      
      // determine value ranges and generate data file
      $minX = NULL;
      $maxX = NULL;
      $minY = NULL;
      $maxY = NULL;
      for($n=0; $n<$maxPoints; $n++) {
        foreach(array_keys($coords) as $i => $key) {
          $x = isset($coords[$key][$n][0]) ? $coords[$key][$n][0] : '';
          if (is_numeric($x) && ($minX === NULL || $x < $minX)) $minX = $x;
          if (is_numeric($x) && $x > $maxX) $maxX = $x;
          $y = isset($coords[$key][$n][1]) ? $coords[$key][$n][1] : '';
          if (is_numeric($y) && ($minY === NULL || $y < $minY)) $minY = $y;
          if (is_numeric($y) && $y > $maxY) $maxY = $y;
          fwrite($df, sprintf("%s%s\t%s", $i > 0 ? "\t" : '', $x, $y));
        }
        fwrite($df, "\n");
      }
      fclose($df);
      
      // determine x tic settings
      $xMin = isset($settings['xMin']) ? $settings['xMin'] : NULL;
      $xMax = isset($settings['xMax']) ? $settings['xMax'] : NULL;
      $xTics = isset($settings['xTics']) ? $settings['xTics'] : NULL;
      if (!isset($xMin) && (isset($xMax) || $xTics)) $xMin = isset($xMax) && preg_match('/%/', $xMax) ? $xMax : '15%';
      if (!isset($xMax) && (isset($xMin) || $xTics)) $xMax = isset($xMin) && preg_match('/%/', $xMin) ? $xMin : '15%';
      if (!isset($xMin)) $xMin = $minX;
      if (!isset($xMax)) $xMax = $maxX;
      if (preg_match('/^([0-9\.]+)%$/', $xMin, $m)) {
        $xMin = floor($minX - ($minX*($m[1]*0.01)));
        if ($xMin < 0) $xMin = 0;
      }
      if (preg_match('/^([0-9\.]+)%$/', $xMax, $m)) $xMax = ceil($maxX + ($maxX*($m[1]*0.01)));
      if (!$xTics) $xTics = 8;
      $xDiff = $xMax - $xMin;
      $xStep = floor($xDiff/$xTics);
      if ($xStep < 1) $xStep = 1;
      
      // determine y tic settings
      $yMin = isset($settings['yMin']) ? $settings['yMin'] : NULL;
      $yMax = isset($settings['yMax']) ? $settings['yMax'] : NULL;
      $yTics = isset($settings['yTics']) ? $settings['yTics'] : NULL;
      if (!isset($yMin) && (isset($yMax) || $yTics)) $yMin = isset($yMax) && preg_match('/%/', $yMax) ? $yMax : '15%';
      if (!isset($yMax) && (isset($yMin) || $yTics)) $yMax = isset($yMin) && preg_match('/%/', $yMin) ? $yMin : '15%';
      if (isset($yMin) && preg_match('/^([0-9\.]+)%$/', $yMin, $m)) {
        $yMin = floor($minY - ($minY*($m[1]*0.01)));
        if ($yMin < 0) $yMin = 0;
      }
      if (isset($yMin)) {
        if (preg_match('/^([0-9\.]+)%$/', $yMax, $m)) $yMax = ceil($maxY + ($maxY*($m[1]*0.01)));
        if (!$yTics) $yTics = 8;
        $yDiff = $yMax - $yMin;
        $yStep = floor($yDiff/$yTics);
        if ($yStep < 1) $yStep = 1;
      }
      
      $img = sprintf('%s/%s-%s.svg', $dir, $this->test, $section);
      BlockStorageTest::printMsg(sprintf('Generating line chart %s with %d data sets and %d points/set. X Label: %s; Y Label: %s; Title: %s', basename($img), count($coords), $maxPoints, $xlabel, $ylabel, $title), $this->verbose, __FILE__, __LINE__);
      
      fwrite($fp, sprintf("#!%s\n", trim(shell_exec('which gnuplot'))));
      fwrite($fp, "reset\n");
      fwrite($fp, sprintf("set terminal svg dashed size 1024,%d fontfile 'font-svg.css' font 'rfont,%d'\n", isset($settings['height']) ? $settings['height'] : 600, $this->options['font_size']+4));
      // custom settings
      if (is_array($settings)) {
        foreach($settings as $key => $setting) {
          // special settings
          if (in_array($key, array('height', 'lines', 'nogrid', 'xLogscale', 'xMin', 'xMax', 'xTics', 'yLogscale', 'yMin', 'yMax', 'yTics'))) continue;
          fwrite($fp, "${setting}\n");
        }
      }
      fwrite($fp, "set autoscale keepfix\n");
      fwrite($fp, "set decimal locale\n");
      fwrite($fp, "set format y \"%'10.0f\"\n");
      fwrite($fp, "set format x \"%'10.0f\"\n");
      if ($xlabel) fwrite($fp, sprintf("set xlabel \"%s\"\n", $xlabel));
      fwrite($fp, sprintf("set xrange [%d:%d]\n", $xMin, $xMax));
      if (isset($settings['xLogscale'])) fwrite($fp, "set logscale x\n");
      else fwrite($fp, sprintf("set xtics %d, %d, %d\n", $xMin, $xStep, $xMax));
      if ($ylabel) fwrite($fp, sprintf("set ylabel \"%s\"\n", $ylabel));
      if (isset($yMin)) {
        fwrite($fp, sprintf("set yrange [%d:%d]\n", $yMin, $yMax));
        if (isset($settings['yLogscale'])) fwrite($fp, "set logscale y\n");
        else fwrite($fp, sprintf("set ytics %d, %d, %d\n", $yMin, $yStep, $yMax));
      }
      if ($title) fwrite($fp, sprintf("set title \"%s\"\n", $title));
      fwrite($fp, "set key reverse Left outside\n");
      fwrite($fp, "set grid\n");
      fwrite($fp, "set style data linespoints\n");
      
      # line styles
      fwrite($fp, "set border linewidth 1.5\n");
      foreach(array_keys($coords) as $i => $key) {
        if (!isset($colors[$i])) break;
        if (isset($settings['lines'][$i+1])) fwrite($fp, sprintf("set style line %d %s\n", $i+1, $settings['lines'][$i+1]));
        else fwrite($fp, sprintf("set style line %d lc rgb '%s' lt 1 lw 3\n", $i+1, $colors[$i]));
      }
      fwrite($fp, "set grid noxtics\n");
      if (!isset($settings['nogrid'])) fwrite($fp, "set grid ytics lc rgb '#dddddd' lw 1 lt 0\n");
      else fwrite($fp, "set grid noytics\n");
      fwrite($fp, "set tic scale 0\n");
      fwrite($fp, sprintf("plot \"%s\"", basename($dfile)));
      $colorPtr = 1;
      foreach(array_keys($coords) as $i => $key) {
        fwrite($fp, sprintf("%s u %d:%d t \"%s\" ls %d%s", $i > 0 ? ", \\\n\"\"" : '', ($i*2)+1, ($i*2)+2, $key, $colorPtr, $bar ? ' w boxes' : ''));
        $colorPtr++;
        if ($colorPtr > count($colors)) $colorPtr = 1;
      }
      
      fclose($fp);
      exec(sprintf('chmod +x %s', $script));
      $cmd = sprintf('cd %s; ./%s > %s 2>/dev/null; echo $?', $dir, basename($script), basename($img));
      $ecode = trim(exec($cmd));
      // exec('rm -f %s', $script);
      // exec('rm -f %s', $dfile);
      if ($ecode > 0) {
        // exec('rm -f %s', $img);
        BlockStorageTest::printMsg(sprintf('Failed to generate line chart - exit code %d', $ecode), $this->verbose, __FILE__, __LINE__, TRUE);
      }
      else {
        BlockStorageTest::printMsg(sprintf('Generated line chart %s successfully', $img), $this->verbose, __FILE__, __LINE__);
        // attempt to convert to PNG using wkhtmltoimage
        if (BlockStorageTest::wkhtmltopdfInstalled()) {
          $cmd = sprintf('wkhtmltoimage %s %s >/dev/null', $img, $png = str_replace('.svg', '.png', $img));
          $ecode = trim(exec($cmd));
          if ($ecode > 0 || !file_exists($png) || !filesize($png)) BlockStorageTest::printMsg(sprintf('Unable to convert SVG image %s to PNG %s (exit code %d)', $img, $png, $ecode), $this->verbose, __FILE__, __LINE__, TRUE);
          else {
            exec(sprintf('rm -f %s', $img));
            BlockStorageTest::printMsg(sprintf('SVG image %s converted to PNG successfully - PNG will be used in report', basename($img)), $this->verbose, __FILE__, __LINE__);
            $img = $png;
          }
        }
        // return full image tag
        if ($html) $chart = sprintf('<img alt="%s" class="plot" src="%s" />', $this->getSubtitle($section), basename($img));
        else $chart = basename($img);
      }
    }
    // error - invalid scripts or unable to open gnuplot files
    else {
      BlockStorageTest::printMsg(sprintf('Failed to generate line chart - either coordinates are invalid or script/data files %s/%s could not be opened', basename($script), basename($dfile)), $this->verbose, __FILE__, __LINE__, TRUE);
      if ($fp) {
        fclose($fp);
        exec('rm -f %s', $script);
      }
    }
    return $chart;
  }
  
  /**
   * generates and testing reports. Returns TRUE on success, FALSE otherwise
   * @param BlockStorageTest[] $controllers the controllers to generate the 
   * reports for
   * @param string $dir optional directory where reports should be generated 
   * in. If not specified, --output will be used
   * @return boolean
   */
  public static function generateReports(&$controllers, $dir=NULL) {
    $keys = array_keys($controllers);
    $options = isset($controllers[$keys[0]]) ? $controllers[$keys[0]]->options : NULL;
    if (!$options || isset($options['noreport']) && $options['noreport']) return FALSE;
    $verbose = isset($options['verbose']) && $options['verbose'];
    
    $generated = FALSE;
    $pageNum = 0;
    if (!$dir) $dir = $options['output'];
    if (is_dir($dir) && is_writable($dir) && mkdir($tdir = sprintf('%s/%d', $dir, rand())) && ($fp = fopen($htmlFile = sprintf('%s/index.html', $tdir), 'w'))) {
      $reportsDir = dirname(dirname(__FILE__)) . '/reports';
      
      // add header
      $tests = array();
      foreach(array_keys($controllers) as $n) $tests[] = $controllers[$n]->test;
      $title = implode(', ', $tests) . ' Block Storage Performance Report';
      ob_start();
      include(sprintf('%s/_header.html', $reportsDir));
      fwrite($fp, ob_get_contents());
      ob_end_clean();
      
      // custom report controllers
      foreach(array_keys($controllers) as $n) {
        if (count($controllers[$n]->subtests)) {
          BlockStorageTest::printMsg(sprintf('Replacing %s test object with %d subtests', $controllers[$n]->test, count($controllers[$n]->subtests)), $verbose, __FILE__, __LINE__);
          foreach(array_keys($controllers[$n]->subtests) as $i) {
            $controllers[count($controllers) - 1] = $controllers[$n]->subtests[$i];
          }
          unset($controllers[$n]);
        }
      }
      
      BlockStorageTest::printMsg(sprintf('Initiating report creation using temporary directory %s', $tdir), $verbose, __FILE__, __LINE__);
      // copy font files
      exec(sprintf('cp %s/font-svg.css %s/', $reportsDir, $tdir));
      exec(sprintf('cp %s/font.css %s/', $reportsDir, $tdir));
      exec(sprintf('cp %s/font.ttf %s/', $reportsDir, $tdir));
      
      foreach(array_keys($controllers) as $n) {
        if (count($controllers[$n]->fio) && $controllers[$n]->wdpcComplete && $controllers[$n]->wdpcIntervals && isset($controllers[$n]->fio['wdpc'])) {
          $ssStart = isset($controllers[$n]->fio['wdpc']) ? ($controllers[$n]->wdpcComplete - 5)*$controllers[$n]->wdpcIntervals : NULL;
          BlockStorageTest::printMsg(sprintf('Generating %s reports in directory %s using steady state start index %d', $controllers[$n]->test, $dir, $ssStart), $verbose, __FILE__, __LINE__);
          $wipcJobs = isset($controllers[$n]->fio['wipc']) ? $controllers[$n]->fio['wipc'] : array();
          $wdpcJobs = $controllers[$n]->fio['wdpc'];
          $ssJobs = array();
          
          foreach($controllers[$n]->fio['wdpc'] as $i => $job) {
            if (isset($job['jobs'][0]['jobname']) && $i >= $ssStart) $ssJobs[$job['jobs'][0]['jobname']] = $job['jobs'][0];
          }
          
          if (count($wdpcJobs) && count($ssJobs)) {
            BlockStorageTest::printMsg(sprintf('Generating %s reports for %d wipc jobs, %d wdpc jobs and %d ss jobs', $controllers[$n]->test, count($wipcJobs), count($wdpcJobs), count($ssJobs)), $verbose, __FILE__, __LINE__);
            
            // use array to represent report header table (10 rows)
            $params = array(
              'platform' => $controllers[$n]->getPlatformParameters(),
              'device' => $controllers[$n]->getDeviceParameters(),
              'setup' => $controllers[$n]->getSetupParameters(),
              'test' => $controllers[$n]->getTestParameters()
            );
            $headers = array();
            for ($i=0; $i<100; $i++) {
              $empty = TRUE;
              $cols = array();
              foreach($params as $type => $vals) {
                if (count($vals) >= ($i + 1)) {
                  $empty = FALSE;
                  $keys = array_keys($vals);
                  $cols[] = array('class' => $type, 'label' => $keys[$i], 'value' => $vals[$keys[$i]]);
                }
                else $cols[] = array('class' => $type, 'label' => '', 'value' => '');
              }
              if (!$empty) $headers[] = $cols;
              else break;
            }
            
            $testPageNum = 0;
            $sections = $controllers[$n]->getReportSections();
            foreach($sections as $section => $label) {
              $test = $controllers[$n]->test;
              if ($content = $controllers[$n]->getReportContent($section, $ssJobs, $tdir)) {
                BlockStorageTest::printMsg(sprintf('Successfully generated %s content (%s) for %s report', $section, $label, $controllers[$n]->test), $verbose, __FILE__, __LINE__);
                $pageNum++;
                $testPageNum++;
                // add page
                ob_start();
                include(sprintf('%s/_page.html', $reportsDir));
                fwrite($fp, ob_get_contents());
                ob_end_clean();
              }
              else if ($content === FALSE) BlockStorageTest::printMsg(sprintf('Skipping %s content for %s report', $section, $controllers[$n]->test), $verbose, __FILE__, __LINE__);
              else BlockStorageTest::printMsg(sprintf('Unable to get %s content for %s report', $section, $controllers[$n]->test), $verbose, __FILE__, __LINE__, TRUE);
            }
          }
        }
        else BlockStorageTest::printMsg(sprintf('Unable to generate %s reports. fio steps: %d; wdpcComplete=%d; wdpcIntervals=%d; wdpc=%d', $controllers[$n]->test, count($controllers[$n]->fio), $controllers[$n]->wdpcComplete, $controllers[$n]->wdpcIntervals, isset($controllers[$n]->fio['wdpc'])), $verbose, __FILE__, __LINE__, TRUE);
      }
      
      // add footer
      ob_start();
      include(sprintf('%s/_footer.html', $reportsDir));
      fwrite($fp, ob_get_contents());
      ob_end_clean();
      
      fclose($fp);
      
      // zip up html report
      if (filesize($htmlFile)) {
        exec(sprintf('cp %s/logo.png %s/', $reportsDir, $tdir));
        $zip = sprintf('%s/report.zip', $tdir);
        exec(sprintf('cd %s; zip %s *; mv %s %s', $tdir, basename($zip), basename($zip), $dir));
        if (!isset($options['nopdfreport']) || !$options['nopdfreport']) {
          // generate postscript report
          $cmd = sprintf('cd %s; wkhtmltopdf -s Letter --footer-left [date] --footer-right [page] index.html report.pdf >/dev/null 2>&1; echo $?', $tdir);
          $ecode = trim(exec($cmd));
          if ($ecode > 0) BlockStorageTest::printMsg(sprintf('Failed to create PDF report'), $verbose, __FILE__, __LINE__, TRUE);
          else {
            BlockStorageTest::printMsg(sprintf('Successfully created PDF report'), $verbose, __FILE__, __LINE__);
            exec(sprintf('mv %s/report.pdf %s', $tdir, $dir));
          }
        }
      }
      
      // remove temporary directory
      if (is_dir($tdir) && strpos($tdir, $dir) === 0 && preg_match('/[0-9]$/', $tdir)) {
        exec(sprintf('rm -rf %s', $tdir));
        BlockStorageTest::printMsg(sprintf('Removed temporary directory %s', $tdir), $verbose, __FILE__, __LINE__);
      }
    } 
    else BlockStorageTest::printMsg(sprintf('Unable to generate reports in directory %s - it either does not exist or is not writable', $dir), $verbose, __FILE__, __LINE__, TRUE);
    
    return $generated;
  }
  
  /**
   * returns the number of CPUs/cores present
   * @return int
   */
  public static function getCpuCount() {
    return trim(shell_exec('nproc'))*1;
  }
  
  /**
   * returns the device path for $target
   * @param string $target the path to check
   * @return string
   */
  public static function getDevice($target) {
    $device = NULL;
    if ($target) {
      if (preg_match('/^\/dev\//', $target)) $device = $target;
      else {
        if (!($device = trim(shell_exec(sprintf('df --output=source %s | sed -e /^Filesystem/d', $target))))) $device = NULL;
      }
    }
    return $device;
  }
  
  /**
   * returns the platform parameters for this test. These are displayed in the 
   * Storage Platform columns
   * @return array
   */
  private function getDeviceParameters() {
    $t = $this->volumeTargets ? 'Volume' : 'Device';
    $capacities = '';
    $purge = '';
    $volInfo = isset($this->options['meta_storage_vol_info']) ? $this->options['meta_storage_vol_info'] : '';
    foreach($this->options['target'] as $target) {
      $capacity = $this->getFreeSpace($target);
      $capacities .= sprintf('%s%s %sB', $capacities ? ', ' : '', $capacity >= 1024 ? round($capacity/1024, 2) : $capacity, $capacity >= 1024 ? 'G' : 'M');
      $purge .= ($purge ? ', ' : '') . (isset($this->purgeMethods[$target]) ? BlockStorageTest::getPurgeMethodDesc($this->purgeMethods[$target]) : 'None');
      if ($this->volumeTargets && !isset($this->options['meta_storage_vol_info'])) {
        $volInfo .= ($volInfo ? ', ' : '') . $this->getFsType($target);
      }
    }
    $params = array(
      'Storage Config' => $this->options['meta_storage_config'],
      "# ${t}s" => count($this->options['target']),
      "${t}s" => implode(', ', $this->options['target']),
      "${t} Capacities" => $capacities,
      'Purge Methods' => $purge,
      'Volume Info' => $volInfo,
      'Drive Model' => isset($this->options['meta_drive_model']) ? $this->options['meta_drive_model'] : '',
      'Drive Type' => isset($this->options['meta_drive_type']) ? $this->options['meta_drive_type'] : '',
      'Drive Interface' => isset($this->options['meta_drive_interface']) ? $this->options['meta_drive_interface'] : '',
      'Notes' => isset($this->options['meta_notes_storage']) ? $this->options['meta_notes_storage'] : ''
    );
    if ($this->deviceTargets) unset($params['Volume Info']);
    return $params;
  }
  
  /**
   * returns the amount of free space available on $target in megabytes
   * @param string $target the directory, volume or device to return free space
   * for
   * @return int
   */
  public function getFreeSpace($target) {
    if ($this->deviceTargets) $freeSpace = (trim(shell_exec($cmd = sprintf('lsblk -n -o size -b %s', $target)))/1024)/1024;
    else {
      $freeSpace = substr(trim(shell_exec($cmd = sprintf('df -B M --output=avail %s | sed -e /Avail/d', $target))), 0, -1)*1;
      if (file_exists($file = sprintf('%s/%s', $target, BlockStorageTest::BLOCK_STORAGE_TEST_FILE_NAME))) $freeSpace += round((filesize($file)/1024)/1024);
    }
    
    if ($freeSpace) BlockStorageTest::printMsg(sprintf('Target %s has %s MB free space', $target, $freeSpace), $this->verbose, __FILE__, __LINE__);
    else {
      $freeSpace = NULL;
      BlockStorageTest::printMsg(sprintf('Unable to get free space for target %s using command: %s', $target, $cmd), $this->verbose, __FILE__, __LINE__, TRUE);
    }
    
    return $freeSpace;
  }
  
  /**
   * returns the file system type for $target
   * @param string $target the volume or device to return the file system type
   * for
   * @return string
   */
  public static function getFsType($target) {
    $fstype = NULL;
    if ($target) {
      if (!($fstype = trim(shell_exec(sprintf('df --output=fstype %s | sed -e /^Type/d', $target))))) $fstype = NULL;
    }
    return $fstype;
  }
  
  /**
   * returns an array containing the hex color codes to use for graphs (as 
   * defined in graph-colors.txt)
   * @return array
   */
  protected final function getGraphColors() {
    if (!count($this->graphColors)) {
      foreach(file(dirname(__FILE__) . '/graph-colors.txt') as $line) {
        if (substr($line, 0, 1) != '#' && preg_match('/([a-zA-Z0-9]{6})/', $line, $m)) $this->graphColors[] = '#' . $m[1];
      }
    }
    return $this->graphColors;
  }
  
  /**
   * returns a meta attribute. $meta is one of the following:
   *   cpu => returns the CPU name and # of cores from /proc/cpuinfo
   * @param string $meta the meta attribute to return
   * @return string
   */
  public static function getMeta($meta) {
    $attr = NULL;
    if ($meta == 'cpu') {
      if ($buffer = trim(shell_exec("cat /proc/cpuinfo | grep 'model name'"))) {
        $pieces = explode("\n", $buffer);
        preg_match('/\s*model name\s*:\s*(.*)$/', $pieces[0], $m);
        $attr = sprintf('%s [%d cores]', str_replace('@ ', '', str_replace('CPU ', '', str_replace('Quad-Core ', '', str_replace('Processor ', '', str_replace('(tm)', '', str_replace('(R)', '', trim($m[1]))))))), count($pieces));
      }
    }
    else if ($meta == 'memory') {
  		if (preg_match('/Mem:\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)/', shell_exec('free -m'), $m)) {
        $mb = $m[1]*1;
        $attr = sprintf('%s %sB', $mb >= 1024 ? round($mb/1024, 2) : $mb, $mb >= 1024 ? 'G' : 'M');
  		}
    }
    else if ($meta == 'os') {
  		$issue = file_get_contents('/etc/issue');
  		foreach(explode("\n", $issue) as $line) {
  			if (!$attr && trim($line)) {
  			  $attr = trim($line);
  			  break;
			  }
  		}
  		// remove superfluous information
  		if ($attr) {
  			$attr = str_replace('(\l).', '', $attr);
  			$attr = str_replace('\n', '', $attr);
  			$attr = str_replace('\l', '', $attr);
  			$attr = str_replace('\r', '', $attr);
  			$attr = str_replace('Welcome to', '', $attr);
  			$attr = trim($attr);
  		}
    }
    return $attr;
  }
  
  /**
   * returns the platform parameters for this test. These are displayed in the 
   * Test Platform columns
   * @return array
   */
  private function getPlatformParameters() {
    return array(
      'Provider' => isset($this->options['meta_provider']) ? $this->options['meta_provider'] : '',
      'Service' => isset($this->options['meta_compute_service']) ? $this->options['meta_compute_service'] : '',
      'Region' => isset($this->options['meta_region']) ? $this->options['meta_region'] : '',
      'Instance ID' => isset($this->options['meta_instance_id']) ? $this->options['meta_instance_id'] : '',
      'CPU' => isset($this->options['meta_cpu']) ? $this->options['meta_cpu'] : '',
      'Memory' => isset($this->options['meta_memory']) ? $this->options['meta_memory'] : '',
      'Operating System' => isset($this->options['meta_os_info']) ? $this->options['meta_os_info'] : '',
      'Test SW' => isset($this->options['meta_test_sw']) ? $this->options['meta_test_sw'] : '',
      'Test ID' => isset($this->options['meta_test_id']) ? $this->options['meta_test_id'] : '',
      'Notes' => isset($this->options['meta_notes_test']) ? $this->options['meta_notes_test'] : ''
    );
  }
  
  /**
   * returns the description to use for a purge method (used in reports)
   * @param string $method purge method to return the description for
   * @return string
   */
  public static function getPurgeMethodDesc($method) {
    $desc = NULL;
    switch($method) {
      case 'secureerase':
        $desc = 'ATA Secure Erase';
        break;
      case 'trim':
        $desc = 'TRIM';
        break;
      case 'zero':
        $desc = 'Zero';
        break;
    }
    return $desc;
  }
  
  /**
   * this sub-class method should return the content associated with $section 
   * using the $jobs given (or all jobs in $this->fio['wdpc']). Return value 
   * should be HTML that can be imbedded into the report. The HTML may include 
   * an image reference without any directory path (e.g. <img src="iops.svg>")
   * returns NULL on error, FALSE if not content required
   * @param string $section the section identifier provided by 
   * $this->getReportSections()
   * @param array $jobs all fio job results occuring within the steady state 
   * measurement window. This is a hash indexed by job name
   * @param string $dir the directory where any images should be generated in
   * @return string
   */
  protected abstract function getReportContent($section, $jobs, $dir);
  
  /**
   * this sub-class method should return a hash identifiying the sections 
   * associated with the test report. The key in the hash should be the 
   * section identifier, and the value the section title
   * @return array
   */
  protected abstract function getReportSections();
  
  /**
   * returns run options represents as a hash
   * @return array
   */
  public static function getRunOptions() {
    // default run argument values
    $defaults = array(
      'active_range' => 100,
      'fio' => 'fio',
      'fio_options' => array(
        'direct' => TRUE,
        'ioengine' => 'libaio',
        'refill_buffers' => FALSE,
        'scramble_buffers' => TRUE
      ),
      'font_size' => 9,
      'highcharts_js_url' => 'http://code.highcharts.com/highcharts.js',
      'highcharts3d_js_url' => 'http://code.highcharts.com/highcharts-3d.js',
      'jquery_url' => 'http://code.jquery.com/jquery-2.1.0.min.js',
      'meta_compute_service' => 'Not Specified',
      'meta_cpu' => BlockStorageTest::getMeta('cpu'),
      'meta_instance_id' => 'Not Specified',
      'meta_memory' => BlockStorageTest::getMeta('memory'),
      'meta_os_info' => BlockStorageTest::getMeta('os'),
      'meta_provider' => 'Not Specified',
      'meta_storage_config' => 'Not Specified',
      'oio_per_thread' => 16,
      'output' => trim(shell_exec('pwd')),
      'precondition_passes' => 2,
      'ss_rounds' => 25,
      'ss_verification' => 10,
      'test' => array('iops'),
      'threads' => '{cpus}',
      'threads_per_target_max' => 4,
      'timeout' => 86400,
      'wd_test_duration' => 60
    );
    $opts = array(
      'active_range:',
      'fio:',
      'font_size:',
      'highcharts_js_url:',
      'highcharts3d_js_url:',
      'jquery_url:',
      'meta_compute_service:',
      'meta_cpu:',
      'meta_drive_interface:',
      'meta_drive_model:',
      'meta_drive_type:',
      'meta_instance_id:',
      'meta_memory:',
      'meta_notes_storage:',
      'meta_notes_test:',
      'meta_provider:',
      'meta_region:',
      'meta_storage_config:',
      'meta_storage_vol_info:',
      'meta_test_id:',
      'meta_test_sw:',
      'no3dcharts',
      'nojson',
      'nopdfreport',
      'noprecondition',
      'nopurge',
      'norandom',
      'noreport',
      'nosecureerase',
      'notrim',
      'nozerofill',
      'oio_per_thread:',
      'os_info:',
      'output:',
      'precondition_passes:',
      'secureerase_pswd:',
      'skip_blocksize:',
      'skip_workload:',
      'ss_rounds:',
      'ss_verification:',
      'target:',
      'test:',
      'threads:',
      'threads_per_target_max:',
      'timeout:',
      'v' => 'verbose',
      'wd_test_duration:'
    );
    $options = BlockStorageTest::parseArgs($opts, array('skip_blocksize', 'skip_workload', 'target', 'test'));
    // explicit fio command
    foreach($defaults as $key => $val) {
      if (!isset($options[$key])) $options[$key] = $val;
    }
    // target/test argument (expand comma separated values)
    foreach(array('target', 'test') as $key) {
      if (isset($options[$key])) {
        $targets = array();
        foreach($options[$key] as $temp) {
          foreach(explode(',', $temp) as $target) $targets[] = trim($target);
        }
        $options[$key] = $targets;
      }
    }
    foreach($_SERVER['argv'] as $arg) {
      if (preg_match('/^\-\-fio_(.*)$/', $arg, $m)) {
        $pieces = explode('=', $m[1]);
        $options['fio_options'][trim(strtolower($pieces[0]))] = isset($pieces[1]) ? trim($pieces[1]) : TRUE;
      }
    }
    // don't use random IO
    if (isset($options['norandom']) && $options['norandom']) {
      unset($options['fio']['refill_buffers']);
      unset($options['fio']['scramble_buffers']);
    }
    // implicit nosecureerase
    if (!isset($options['secureerase_pswd'])) $options['nosecureerase'] = TRUE;
    // implicit nopurge
    if (isset($options['nosecureerase']) && $options['nosecureerase'] && isset($options['notrim']) && $options['notrim'] && isset($options['nozerofill']) && $options['nozerofill']) $options['nopurge'] = TRUE;
    // threads is based on number of CPUs
    if (isset($options['threads']) && preg_match('/{cpus}/', $options['threads'])) {
      $options['threads'] = str_replace(' ', '', str_replace('{cpus}', BlockStorageTest::getCpuCount(), $options['threads']));
      // expression
      if (preg_match('/[\*\+\-\/]/', $options['threads'])) {
        eval(sprintf('$options["threads"]=%s;', $options['threads']));
      }
      $options['threads'] *= 1;
      if ($options['threads'] <= 0) $options['threads'] = 1;
      
      // adjust for number of targets
      if (isset($options['target']) && count($options['target']) > 1) {
        $options['threads'] = round($options['threads']/count($options['target']));
        if ($options['threads'] == 0) $options['threads'] = 1;
      }
      
      // adjust for threads_per_target_max
      if (isset($options['threads_per_target_max']) && $options['threads'] > $options['threads_per_target_max']) $options['threads'] = $options['threads_per_target_max'];
      
    }
    return $options;
  }
  
  /**
   * this sub-class method should return a hash of setup parameters - these are
   * label/value pairs displayed in the bottom 8 rows of the Set Up Parameters 
   * columns in the report page headers
   * @return array
   */
  protected abstract function getSetupParameters();
  
  /**
   * this sub-class method should return the subtitle for a given test and 
   * section
   * @param string $section the section identifier to return the subtitle for
   * @return string
   */
  protected abstract function getSubtitle($section);
  
  /**
   * returns a new instance of a BlockStorageTest object for the $test and 
   * $options specified
   * @param string $test the type of block storage test
   * @param array $options the test options
   * @return BlockStorageTest
   */
  public static function &getTestController($test, $options) {
    $controller = NULL;
    if ($test && file_exists($file = dirname(__FILE__) . '/BlockStorageTest' . strtoupper(substr($test, 0, 1)) . substr($test, 1) . '.php')) {
      require_once($file);
      $className = str_replace('.php', '', basename($file));
      if (class_exists($className)) {
        $controller = new $className($options);
        $controller->test = $test;
        $controller->options = $options; 
        // determine target types (device or volume)
        foreach($options['target'] as $target) {
          $device = BlockStorageTest::getDevice($target);
          $device == $target ? $controller->deviceTargets = TRUE : $controller->volumeTargets = TRUE;
        }
        $controller->verbose = isset($options['verbose']) && $options['verbose'];
      }
    }
    return $controller;
  }
  
  /**
   * this sub-class method should return a hash of test parameters - these are
   * label/value pairs displayed in the bottom 8 rows of the Test Parameters 
   * columns in the report page headers
   * @return array
   */
  protected abstract function getTestParameters();
  
  /**
   * returns the base volume path for $target. if $target is a device 
   * reference, the same reference will be returned
   * @param string $target the path to check
   * @return string
   */
  public static function getVolume($target) {
    $volume = NULL;
    if ($target) {
      if (preg_match('/^\/dev\//', $target)) $volume = $target;
      else {
        if (!($volume = trim(shell_exec(sprintf('df --output=target %s | sed -e /^Mounted/d', $target))))) $volume = NULL;
      }
    }
    return $volume;
  }
  
  /**
   * returns TRUE if $target references a rotational device
   * @param string $target the device or path to check
   * @return boolean
   */
  public static function isRotational($target) {
    $rotational = FALSE;
    if ($device = BlockStorageTest::getDevice($target)) {
      if (file_exists($file = sprintf('/sys/block/%s/queue/rotational', basename($device)))) {
        $rotational = trim(file_get_contents($file)) == '1';
      }
      else BlockStorageTest::printMsg(sprintf('Unable to check if %s is rotational because file %s does not exist', $device, $file), TRUE, __FILE__, __LINE__, TRUE);
    }
    return $rotational;
  }
  
  /**
   * returns TRUE if the $metrics provided constitute steady state. The 
   * --ss_verification parameter defines thresholds for steady state. Per the 
   * SNIA test specification, the default value is 10%
   * @param array $metrics the metrics to use to check for steady state (x/y 
   * coords)
   * @return boolean
   */
  protected function isSteadyState($metrics) {
    $steadyState = FALSE;
    if (is_array($metrics) && count($metrics) == 5) {
      $n = 5;
      $sum_x = array_sum(array_keys($metrics));
      $sum_y = array_sum($metrics);
      $mean_x = round($sum_x/$n, 3);
      $mean_y = round($sum_y/$n, 3);
      $sum_xy = 0;
      $sum_x_sq = 0;
      foreach($metrics as $x => $y) {
        $sum_xy += $x*$y;
        $sum_x_sq += $x*$x;
      }
      $slope = round((($n * $sum_xy) - ($sum_x * $sum_y))/(($n * $sum_x_sq) - ($sum_x * $sum_x)), 3);
      $yIntercept = round((($sum_y * $sum_x_sq) - ($sum_x * $sum_xy))/(($n * $sum_x_sq) - ($sum_x * $sum_x)), 3);
      BlockStorageTest::printMsg(sprintf('Calculated slope=%s; y intercept=%s; using n=%s; sum_y=%s; sum_x_sq=%s; sum_x=%s; sum_xy=%s; least squares formula: %s', $slope, $yIntercept, $n, $sum_y, $sum_x_sq, $sum_x, $sum_xy, sprintf('%s * R + %s', $slope, $yIntercept)), $this->verbose, __FILE__, __LINE__);
      
      $ratio = $this->options['ss_verification']*0.01;
      $maxSlopeExcursion = $mean_y * $ratio;
      $maxDataExcursion = $maxSlopeExcursion * 2;
      $first = NULL;
      $last = NULL;
      $min = NULL;
      $max = NULL;
      $largestDataExcursion = NULL;
      $largestSlopeExcursion = round(abs($slope*4), 1);
      $squares = array();
      foreach($metrics as $i => $metric) {
        if ($min === NULL || $metric < $min) $min = $metric;
        if ($max === NULL || $metric > $max) $max = $metric;
        if ($first === NULL) $first = $metric;
        $last = $metric;
      }
      $largestDataExcursion = $max - $min;
      
      if ($steadyState = $largestDataExcursion <= $maxDataExcursion && $largestSlopeExcursion <= $maxSlopeExcursion) {
        $this->ssData['metrics'] = $metrics;
        $this->ssData['average'] = $mean_y;
        $this->ssData['maxDataExcursion'] = $maxDataExcursion;
        $this->ssData['maxSlopeExcursion'] = $maxSlopeExcursion;
        $this->ssData['largestDataExcursion'] = $largestDataExcursion;
        $this->ssData['largestSlopeExcursion'] = $largestSlopeExcursion;
        $this->ssData['slope'] = $slope;
        $this->ssData['yIntercept'] = $yIntercept;
      }
      BlockStorageTest::printMsg(sprintf('Steady state check complete: ratio=%s; average=%s; allowed max data excursion=%s; allowed max slope excursion=%s; actual max data excursion=%s; actual max slope excursion=%s; steady state=%s', $ratio, $mean_y, $maxDataExcursion, $maxSlopeExcursion, $largestDataExcursion, $largestSlopeExcursion, $steadyState ? 'YES' : 'NO'), $this->verbose, __FILE__, __LINE__);
    }
    return $steadyState;
  }
  
  /**
   * this method creates an arguments hash containing the command line args as 
   * a where the key is the long argument name and the value is the value for
   * that argument. boolean arguments will automatically be converted to PHP bools
   * @param array $opts the options definition - a hash of short/long argument 
   * names. if the key is numeric, it will be assumed to not have a short argument 
   * option. if the argument name has a colon (:) at the end, it will be assumed to 
   * be an argument that requires a value - otherwise it will be assumed to be a 
   * flag argument
   * @param array $arrayArgs if specified, options with these names will be 
   * forced to arrays even if they only contain a single argument value and 
   * arguments that repeat that area not included in this argument will be set 
   * to the first specified value (others will be discarded)
   * @return array
   */
  public static function parseArgs($opts, $arrayArgs=NULL) {
    global $argv;
    $key = NULL;
    $val = NULL;
    $options = array();
    foreach($argv as $arg) {
      if (preg_match('/^\-\-?([^=]+)\=?(.*)$/', $arg, $m)) {
        if ($key && isset($options[$key])) {
          if (!is_array($options[$key])) $options[$key] = array($options[$key]);
          $options[$key][] = $val;
        }
        else if ($key) $options[$key] = $val;
        $key = $m[1];
        $val = isset($m[2]) ? $m[2] : '';
      }
      else if ($key) $val .= ' ' . $arg;
    }
    if ($key && isset($options[$key])) {
      if (!is_array($options[$key])) $options[$key] = array($options[$key]);
      $options[$key][] = $val;
    }
    else if ($key) $options[$key] = $val;

    foreach($opts as $short => $long) {
      $key = str_replace(':', '', $long);
      if (preg_match('/[a-z]:?$/', $short) && isset($options[$short = substr($short, 0, 1)])) {
        if (isset($options[$key])) {
          if (!is_array($options[$key])) $options[$key] = array($options[$key]);
          $options[$key][] = $options[$short];
        }
        else $options[$key] = $options[$short];
        unset($options[$short]);
      }
      // check for environment variable
      if (!isset($options[$key]) && preg_match('/^meta_/', $key) && getenv('bm_' . str_replace('meta_', '', $key))) $options[$key] = getenv('bm_' . str_replace('meta_', '', $key));
      if (!isset($options[$key]) && getenv("bm_param_${key}")) $options[$key] = getenv("bm_param_${key}");
      // convert booleans
      if (isset($options[$key]) && !strpos($long, ':')) $options[$key] = $options[$key] === '0' ? FALSE : TRUE;
      // set array parameters
      if (is_array($arrayArgs)) {
        if (isset($options[$key]) && in_array($key, $arrayArgs) && !is_array($options[$key])) $options[$key] = array($options[$key]);
        else if (isset($options[$key]) && !in_array($key, $arrayArgs) && is_array($options[$key])) $options[$key] = $options[$key][0];
      }
      // remove empty values
      if (!isset($options[$key])) unset($options[$key]);
    }
    
    // remove quotes
    foreach(array_keys($options) as $i) {
      if (is_array($options[$i])) {
        foreach(array_keys($options[$i]) as $n) $options[$i][$n] = BlockStorageTest::stripQuotes($options[$i][$n]);
      }
      else $options[$i] = BlockStorageTest::stripQuotes($options[$i]);
    }

    return $options;
  }
  
  /**
   * Prints a message to stdout
   * @param string $msg the message to print
   * @param boolean $verbose whether or not verbose output mode is enabled. If 
   * not enabled, message will not be printed (unless $err==TRUE)
   * @param string $file optional name of the file generating the message
   * @param int $line optional line number in the file generating the message
   * @param boolean $err if this message an error?
   * @return void
   */
  public static function printMsg($msg, $verbose=FALSE, $file=NULL, $line=NULL, $err=FALSE) {
    if ($verbose || $err) {
    	printf("%-24s %-8s %-24s %s\n", 
    	       date('m/d/Y H:i:s T'), 
    	       BlockStorageTest::runTime() . 's', 
    				 str_replace('.php', '', basename($file ? $file : __FILE__)) . ':' . ($line ? $line : __LINE__),
    				 ($err ? 'ERROR: ' : '') . $msg);
    }
  }
  
  /**
   * Purges test devices/volumes prior to testing. Purge methods are determined 
   * by the type and capabilities of the target devices/volumes and the runtime 
   * arguments --nosecureerase, --notrim and --nozerofill. Returns TRUE on 
   * success, FALSE otherwise. Purge methods are tracked on a per device basis 
   * using the instance variable $purgeMethods
   * @return boolean
   */
  public final function purge() {
    $purgeCount = 0;
    $nopurge = isset($this->options['nopurge']) && $this->options['nopurge'];
    $nosecureerase = isset($this->options['nosecureerase']) && $this->options['nosecureerase'];
    $notrim = isset($this->options['notrim']) && $this->options['notrim'];
    $nozerofill = isset($this->options['nozerofill']) && $this->options['nozerofill'];
    if (!$nopurge) {
      foreach($this->options['target'] as $target) {
        $purged = FALSE;
        $volume = BlockStorageTest::getVolume($target);
        $rotational = BlockStorageTest::isRotational($target);
        BlockStorageTest::printMsg(sprintf('Attempting to purge %srotational target %s with --nosecureerase=%d; --notrim=%d; --nozerofill=%d', $rotational ? '' : 'non-', $target, $nosecureerase ? '1' : '0', $notrim ? '1' : '0', $nozerofill ? '1' : '0'), $this->verbose, __FILE__, __LINE__);
        // try ATA secure erase
        if ($this->deviceTargets && !$nosecureerase) {
          BlockStorageTest::printMsg(sprintf('Attempting ATA secure erase for target %s', $target), $this->verbose, __FILE__, __LINE__);
          $cmd = sprintf('hdparm --user-master u --security-erase "%s" %s >/dev/null 2>&1; echo $?', $this->options['secureerase_pswd'], $target);
          $ecode = trim(exec($cmd));
          if ($ecode > 0) BlockStorageTest::printMsg(sprintf('ATA secure erase not supported or failed for target %s', $target), $this->verbose, __FILE__, __LINE__);
          else {
            BlockStorageTest::printMsg(sprintf('ATA secure erase successful for target %s', $target), $this->verbose, __FILE__, __LINE__);
            $this->purgeMethods[$target] = 'secureerase';
            $purged = TRUE;
          }
        }
        else BlockStorageTest::printMsg(sprintf('ATA secure erase not be attempted for %s because %s', $target, $nosecureerase ? '--nosecureerase argument was specified (or implied due to lack of --secureerase_pswd argument)' : 'it is not a device'), $this->verbose, __FILE__, __LINE__);

        // next try TRIM
        if (!$purged && !$rotational && !$notrim) {
          BlockStorageTest::printMsg(sprintf('Attempting TRIM for volume %s', $volume), $this->verbose, __FILE__, __LINE__);
          $cmd = sprintf(($this->deviceTargets ? 'blkdiscard' : 'fstrim') . ' %s >/dev/null 2>&1; echo $?', $this->deviceTargets ? $target : $volume);
          $ecode = trim(exec($cmd));
          if ($ecode > 0) BlockStorageTest::printMsg(sprintf('TRIM not supported or failed for target %s (exit code %d)', $target, $ecode), $this->verbose, __FILE__, __LINE__);
          else {
            BlockStorageTest::printMsg(sprintf('TRIM successful for target %s', $target), $this->verbose, __FILE__, __LINE__);
            $this->purgeMethods[$target] = 'trim';
            $purged = TRUE;
          }
        }
        else if (!$purged) BlockStorageTest::printMsg(sprintf('TRIM not attempted for target %s because %s', $target, $notrim ? '--notrim argument was specified' : 'device is rotational'), $this->verbose, __FILE__, __LINE__);
        
        // finally try zero filling
        if (!$purged && !$nozerofill) {
          $size = $this->getFreeSpace($target);
          
          // adjust for active range and volume target free space buffer
          if ($this->options['active_range'] < 100) $size *= ($this->options['active_range'] * 0.01);
          else if ($this->volumeTargets) $size -= BlockStorageTest::BLOCK_STORAGE_TEST_FREE_SPACE_BUFFER;
          $size = round($size);
          
          if ($size < 1) BlockStorageTest::printMsg(sprintf('Target %s does not have sufficient space (%d MB) to accomodate free space buffer (%d MB)', $target, $size + BlockStorageTest::BLOCK_STORAGE_TEST_FREE_SPACE_BUFFER, BlockStorageTest::BLOCK_STORAGE_TEST_FREE_SPACE_BUFFER), $this->verbose, __FILE__, __LINE__, TRUE);
          else {
            BlockStorageTest::printMsg(sprintf('Attempting to zero fill target %s with %d MB. This may take a while...', $target, $size), $this->verbose, __FILE__, __LINE__);
            $cmd = sprintf('dd if=/dev/zero of=%s bs=1M count=%d >/dev/null 2>&1; echo $?', $file = $target . ($this->volumeTargets ? '/'. BlockStorageTest::BLOCK_STORAGE_TEST_FILE_NAME : ''), $size);
            $ecode = trim(exec($cmd));
            // delete zero file from volume type targets
            if ($this->volumeTargets) {
              BlockStorageTest::printMsg(sprintf('Removing temporary zero fill file %s', $file), $this->verbose, __FILE__, __LINE__);
              exec(sprintf('rm -f %s', $file));
            }
            if ($ecode > 0) BlockStorageTest::printMsg(sprintf('Zero fill failed for target %s (exit code %d)', $target, $ecode), $this->verbose, __FILE__, __LINE__);
            else {
              BlockStorageTest::printMsg(sprintf('Zero fill successful for target %s', $target), $this->verbose, __FILE__, __LINE__);
              $this->purgeMethods[$target] = 'zero';
              $purged = TRUE;
            } 
          }
        }
        else if (!$purged) BlockStorageTest::printMsg(sprintf('Zero fill not attempted for target %s because %s', $target, $nozerofill ? '--nozerofill argument was specified' : 'it is not a device'), $this->verbose, __FILE__, __LINE__);
        
        if ($purged) {
          BlockStorageTest::printMsg(sprintf('Target %s purged successfully using %s', $target, $this->purgeMethods[$target]), $this->verbose, __FILE__, __LINE__);
          $purgeCount++;
        }
        else BlockStorageTest::printMsg(sprintf('Target %s could not be purged', $target), $this->verbose, __FILE__, __LINE__);
      } 
    }
    return $purgeCount == count($this->options['target']);
  }
  
  /**
   * returns the current execution time
   * @return float
   */
  public static function runTime() {
  	global $block_storage_test_start;
  	return round(microtime(TRUE) - $block_storage_test_start);
  }
  
  /**
   * Trims and removes leading and trailing quotes from a string 
   * (e.g. "some string" => some string; 'some string' => some string)
   * @param string $string the string to remove quotes from
   * @return string
   */
  public static function stripQuotes($string) {
    $string = trim($string);
    if (preg_match('/^"(.*)"$/', $string, $m)) $string = $m[1];
    else if (preg_match("/^'(.*)'\$/", $string, $m)) $string = $m[1];
    return $string;
  }
  
  /**
   * validates test dependencies including the following:
   *   fio         Performs actual testing - version 2.0+ required
   *   gnuplot     Generates graphs per the SNIA test specification. These graphs
   *               are used in the PDF report
   *   hdparm      Used for ATA secure erase (when supported)
   *   util-linux  For TRIM operations using `blkdiscard` and `fstrim` (when 
   *               supported). Not required if test targets are rotational
   *   wkhtmltopdf Generates PDF version of report - download from 
   *               http://wkhtmltopdf.org
   *   zip         Archives HTML test report into a single zip file
   * @param array $options the run options (see BlockStorageTest::getRunOptions)
   * @return array
   */
  public static function validateDependencies($options) {
    $dependencies = array('fio' => 'fio');
    // reporting dependencies
    if (!isset($options['noreport']) || !$options['noreport']) {
      $dependencies['gnuplot'] = 'gnuplot';
      $dependencies['zip'] = 'zip';
      if (!isset($options['nopdfreport']) || !$options['nopdfreport']) $dependencies['wkhtmltopdf'] = 'wkhtmltopdf';
    }
    // ATA secure erase requires hdparm
    if ((!isset($options['nosecureerase']) || !$options['nosecureerase']) && isset($options['secureerase_pswd'])) $dependencies['hdparm'] = 'hdparm';
    // non-rotational devices require trim
    if (!isset($options['notrim']) || !$options['notrim']) {
      $nonrotational = FALSE;
      foreach($options['target'] as $target) {
        if (!BlockStorageTest::isRotational($target)) {
          $nonrotational = TRUE;
          break;
        }
      }
      if (!$nonrotational) $dependencies['fstrim'] = 'util-linux';
    }
    // now check if present
    foreach($dependencies as $c => $dependency) {
      $cmd = sprintf('which %s; echo $?', $c);
      $ecode = trim(exec($cmd));
      if ($ecode == 0) unset($dependencies[$c]);
    }
    return $dependencies;
  }
  
  /**
   * validates fio version and settings. Returns TRUE if it is valid, FALSE 
   * otherwise
   * @param array $options the run options (see BlockStorageTest::getRunOptions)
   * @return boolean
   */
  public static function validateFio($options) {
    $fio = trim(shell_exec($options['fio'] . ' --version 2>&1'));
    return preg_match('/^fio\-2/', $fio) ? TRUE : FALSE;
  }
  
  /**
   * validate script options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @param array $options the option values to validate
   * @param array $validate validation hash - indexed by argument name where 
   * the value is a hash of validation constraints. The following constraints 
   * are supported:
   *   min:      argument numeric and >= this value
   *   max:      argument numeric and <= this value
   *   option:   argument must be found in this value (array)
   *   required: argument is required
   *   write:    argument is in the file syste path and writeable
   * @return array
   */
  public static function validateOptions($options, $validate) {
    $invalid = array();
    foreach($validate as $arg => $constraints) {
      foreach($constraints as $constraint => $cval) {
        $err = NULL;
        $vals = isset($options[$arg]) ? $options[$arg] : NULL;
        if (!is_array($vals)) $vals = array($vals);
        foreach($vals as $val) {
          // printf("Validate --%s=%s using constraint %s\n", $arg, $val, $constraint);
          switch($constraint) {
            case 'min':
            case 'max':
              if ($val && !is_numeric($val)) $err = sprintf('%s is not numeric', $val);
              else if (is_numeric($val) && $constraint == 'min' && $val < $cval) $err = sprintf('%d is less then minimum permitted value %d', $val, $cval);
              else if (is_numeric($val) && $constraint == 'max' && $val > $cval) $err = sprintf('%d is greater then maximum permitted value %d', $val, $cval);
              break;
            case 'option':
              if ($val && !in_array($val, $cval)) $err = sprintf('%s must be one of the following: %s', $val, implode(', ', $cval));
              break;
            case 'required':
              if ($val === NULL) $err = sprintf('argument is required', $arg);
              break;
            case 'write':
              if ($val && !file_exists($val)) $err = sprintf('%s is not a valid path', $val);
              else if ($val && !is_writable($val)) $err = sprintf('%s is not writable', $val);
              break;
          }
          if ($err) {
            $invalid[$arg] = $err; 
            break;
          }
        }
      }
    }
    return $invalid;
  }
  
  /**
   * validate run options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @param array $options the run options (see BlockStorageTest::getRunOptions)
   * @return array
   */
  public static function validateRunOptions($options) {
    $validate = array(
      'active_range' => array('min' => 1, 'max' => 100),
      'font_size' => array('min' => 6, 'max' => 64),
      'oio_per_thread' => array('min' => 1, 'max' => 256),
      'output' => array('write' => TRUE),
      'precondition_passes' => array('min' => 1, 'max' => 5),
      'skip_blocksize' => array('option' => array('1m', '128k', '64k', '32k', '16k', '8k', '512b')),
      'skip_workload' => array('option' => array('100/0', '95/5', '65/35', '50/50', '35/65', '5/95')),
      'ss_rounds' => array('min' => 5, 'max' => 100),
      'ss_verification' => array('min' => 1, 'max' => 100),
      'target' => array('required' => TRUE, 'write' => TRUE),
      'test' => array('option' => array('iops', 'throughput', 'latency', 'wsat', 'hir', 'xsr', 'ecw', 'dirth'), 'required' => TRUE),
      'threads' => array('min' => 1),
      'threads_per_target_max' => array('min' => 1),
      'timeout' => array('min' => 3600),
      'wd_test_duration' => array('min' => 10)
    );
    if (!($valid = BlockStorageTest::validateOptions($options, $validate))) {
      $devices = 0;
      $volumes = 0;
      // device and volume type targets cannot be mixed
      foreach($options['target'] as $target) {
        $device = BlockStorageTest::getDevice($target);
        $device == $target ? $devices++ : $volumes++;
      }
      if ($devices && $volumes) $valid = array('target' => 'Device and volume type targets cannot be mixed');
    }
    return $valid;
  }
  
  /**
   * Performs workload independent preconditioning for test devices/volumes 
   * prior to testing. This consists of a 2X 128K sequential write across test
   * device targets. This step is skipped if the target is not a device. 
   * Returns TRUE on success, FALSE otherwise. Preconditioned state is tracked
   * with the $wipc instance variable
   * @param string $bs the block size to use for preconditioning. defaults to 
   * 128k
   * @return boolean
   */
  public final function wipc($bs='128k') {
    $noprecondition = isset($this->options['noprecondition']) && $this->options['noprecondition'];
    if (!$noprecondition) {
      BlockStorageTest::printMsg(sprintf('Attempting workload independent preconditioning (%dX 128k sequential writes on entire device). This may take a while...', $this->options['precondition_passes']), $this->verbose, __FILE__, __LINE__);
      for($i=1; $i<=$this->options['precondition_passes']; $i++) {
        $opts = array('blocksize' => $bs, 'rw' => 'write');
        BlockStorageTest::printMsg(sprintf('Attempting workload independent precondition pass %d of %d', $i, $this->options['precondition_passes']), $this->verbose, __FILE__, __LINE__);
        if ($this->fio($opts, 'wipc')) {
          $this->wipc = TRUE;
          BlockStorageTest::printMsg(sprintf('Workload independent precondition pass %d of %d successful', $i, $this->options['precondition_passes']), $this->verbose, __FILE__, __LINE__);
        }
        else {
          BlockStorageTest::printMsg(sprintf('Workload independent precondition pass %d of %d failed. Preconditioning will stop', $i, $this->options['precondition_passes']), $this->verbose, __FILE__, __LINE__, TRUE);
          break;
        }
      }
    }
    return $this->wipc;
  }
  
  /**
   * returns TRUE if wkhtmltopdf is installed, FALSE otherwise
   * @return boolean
   */
  public final static function wkhtmltopdfInstalled() {
    $ecode = trim(exec('which wkhtmltopdf; echo $?'));
    return $ecode == 0;
  }
  
  
  /**
   * Performs workload dependent preconditioning - this method must be 
   * implemented by sub-classes. It should return one of the following 
   * values:
   *   TRUE:  preconditioning successful and steady state achieved
   *   FALSE: preconditioning successful but steady state not achieved
   *   NULL:  preconditioning failed
   * @return boolean
   */
  public abstract function wdpc();
  
}
?>
