<?php
/**
 * Block storage test implementation for the Latency test
 */
class BlockStorageTestLatency extends BlockStorageTest {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BlockStorageTest::getTestController static method
   */
  protected function BlockStorageTestLatency() {}
    
  /**
   * this sub-class method should return the content associated with $section 
   * using the $jobs given (or all jobs in $this->fio['wdpc']). Return value 
   * should be HTML that can be imbedded into the report. The HTML may include 
   * an image reference without any directory path (e.g. <img src="iops.png>")
   * return NULL on error
   * @param string $section the section identifier provided by 
   * $this->getReportSections()
   * @param array $jobs all fio job results occuring within the steady state 
   * measurement window. This is a hash indexed by job name
   * @param string $dir the directory where any images should be generated in
   * @return string
   */
  protected function getReportContent($section, $jobs, $dir) {
    $content = NULL;
    switch($section) {
      case 'ss-convergence-avg':
        // TODO
        break;
      case 'ss-convergence-max':
        // TODO
        break;
      case 'ss-measurement':
        // TODO
        break;
      case 'tabular':
        // TODO
        break;
      case '2d-plot-1024':
        // TODO
        break;
      case '3d-plot-avg':
        // TODO
        break;
      case '3d-plot-max':
        // TODO
        break;
    }
    // TODO: remove
    $content = $section;
    return $content;
  }

  /**
   * this sub-class method should return a hash identifiying the sections 
   * associated with the test report. The key in the hash should be the 
   * section identifier, and the value, the section title
   * @return array
   */
  protected function getReportSections() {
    return array(
      'ss-convergence-avg' => 'Steady State Convergence Plot - Average Latency - 100% Writes',
      'ss-convergence-max' => 'Steady State Convergence Plot - Maximum Latency - 100% Writes',
      'ss-measurement' => 'Steady State Measurement Window - RND/4KiB',
      'tabular' => 'Average and Maximum Response Time - All RW Mix &amp; BS - Tabular Data',
      '3d-plot-avg' => 'Average Latency vs. BS and R/W Mix - 3D Plot',
      '3d-plot-max' => 'Maximum Latency vs. BS and R/W Mix - 3D Plot'
    );
  }
  
  /**
   * this sub-class method should return a hash of setup parameters - these are
   * label/value pairs displayed in the bottom 8 rows of the Set Up Parameters 
   * columns in the report page headers
   * @return array
   */
  protected function getSetupParameters() {
    // TODO
  }

  /**
   * this sub-class method should return the subtitle for a given test and 
   * section
   * @param string $section the section identifier to return the subtitle for
   * @return string
   */
  protected function getSubtitle($section) {
    $subtitle = NULL;
    switch($section) {
      case '3d-plot-max':
        $subtitle = 'LAT - 0.5,4,8KiB x R, 65:35, W';
        break;
      default:
        $subtitle = 'LATENCY - Response Time OIO=1'
        break;
    }
    return $subtitle;
  }

  /**
   * this sub-class method should return a hash of test parameters - these are
   * label/value pairs displayed in the bottom 8 rows of the Test Parameters 
   * columns in the report page headers
   * @return array
   */
  protected function getTestParameters() {
    // TODO
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
  public function wdpc() {
    $status = NULL;
    $verbose = isset($this->options['verbose']) && $this->options['verbose'];
    BlockStorageTest::printMsg(sprintf('Initiating workload dependent preconditioning and steady state for LATENCY test'), $verbose, __FILE__, __LINE__);
    // TODO
    return $status;
  }
  
}
?>
