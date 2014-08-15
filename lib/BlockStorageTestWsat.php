<?php
/**
 * Block storage test implementation for the Write Saturation test
 */
class BlockStorageTestWsat extends BlockStorageTest {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BlockStorageTest::getTestController static method
   */
  protected function BlockStorageTestWsat() {}
    
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
    BlockStorageTest::printMsg(sprintf('Initiating workload dependent preconditioning and steady state for WSAT test'), $verbose, __FILE__, __LINE__);
    // TODO
    return $status;
  }
  
}
?>
