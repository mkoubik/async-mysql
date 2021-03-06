<?php

namespace Atrox;

use React\Promise\Deferred;

class AsyncMysql {
  private $loop;
  private $makeConnection;

  // todo: there should be connection pool right in this place, but due to 
  // impeding apocalypse, I had no time to actually write it, because I was in 
  // my bunker surrounded by canned food and guns

  /**
   * @param callable fucnction that makes connection
   * @param React\EventLoop\LoopInterface
   */
  function __construct($makeConnection, $loop) {
    $this->makeConnection = $makeConnection;
    $this->loop = $loop;
  }

  /**
   * @param callable function that makes query
   * @return React\Promise\PromiseInterface
   */
  function query($makeQuery) {
    $conn = call_user_func($this->makeConnection);
    $query = call_user_func($makeQuery, $conn);
    $conn->query($query, MYSQLI_ASYNC);

    $defered = new Deferred();
    $resolver = $defered->resolver();
    $this->loop->addPeriodicTimer(0.002, function ($timer) use($conn, $resolver) {
      $links = $errors = $reject = array($conn);
      mysqli_poll($links, $errors, $reject, 0); // don't wait, just check
      if (($read = in_array($conn, $links, true)) || ($err = in_array($conn, $errors, true)) || ($rej = in_array($conn, $reject, true))) {
        if ($read) {
          $result = $conn->reap_async_query();
          if ($result === false) {
            $resolver->reject($conn->error);
          } else {
            $resolver->resolve($result);
          }
        } elseif ($err) {
          $resolver->reject($conn->error_list);
        } else {
          $resolver->reject('Query was rejected');
        }
        $timer->cancel();
        $conn->close();
      }
    });
    return $defered->promise();
  }
}
