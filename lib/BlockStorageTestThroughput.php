<?php
/**
 * Block storage test implementation for the Throughput test
 */
class BlockStorageTestThroughput extends BlockStorageTest {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BlockStorageTest::getTestController static method
   */
  protected function BlockStorageTestThroughput() {}
    
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
      case 'ss-convergence-write-1024':
        // TODO
        break;
      case 'ss-convergence-read-1024':
        // TODO
        break;
      case 'ss-measurement-1024':
        // TODO
        break;
      case 'tabular-1024':
        // TODO
        break;
      case '2d-plot-1024':
        // TODO
        break;
      case 'ss-convergence-write-128':
        // TODO
        break;
      case 'ss-convergence-read-128':
        // TODO
        break;
      case 'ss-measurement-128':
        // TODO
        break;
      case 'tabular-128':
        // TODO
        break;
      case '2d-plot-128':
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
      'ss-convergence-write-1024' => 'Throughput Test - SS Convergence - Write 1024KiB',
      'ss-convergence-read-1024' => 'Throughput Test - SS Convergence - Read 1024 KiB',
      'ss-measurement-1024' => 'Steady State Measurement Window - SEQ/1024 KiB',
      'tabular-1024' => 'Throughput - All RW Mix &amp; BS - Tabular Data 1024KiB',
      '2d-plot-1024' => 'Throughput - All RW Mix &amp; BS - 2D Plot 1024KiB',
      'ss-convergence-write-128' => 'Throughput Test - SS Convergence - Write 128KiB',
      'ss-convergence-read-128' => 'Throughput Test - SS Convergence - Read 128KiB',
      'ss-measurement-128' => 'Steady State Measurement Window - SEQ/128 KiB',
      'tabular-128' => 'Throughput -All RW Mix &amp; BS - Tabular Data 128KiB',
      '2d-plot-128' => 'Throughput -All RW Mix &amp; BS - 2D Plot 128KiB'
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
      case 'ss-convergence-write-1024':
        $subtitle = 'TP - SEQ 1024KiB &amp; 128KiB';
        break;
      case 'ss-convergence-read-1024':
      case 'ss-measurement-1024':
      case 'tabular-1024':
      case '2d-plot-1024':
        $subtitle = 'TP - SEQ 1024KiB';
        break;
      case 'ss-convergence-write-128':
        $subtitle = 'TP - SEQ 128KiB';
        break;
      case 'ss-convergence-read-128':
      case 'ss-measurement-128':
      case 'tabular-128':
      case '2d-plot-128':
        $subtitle = 'TP - SEQ 1024KiB / 128KiB';
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
    BlockStorageTest::printMsg(sprintf('Initiating workload dependent preconditioning and steady state for THROUGHPUT test'), $verbose, __FILE__, __LINE__);
    // TODO
    return $status;
  }
  
}
?>
