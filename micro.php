<?php

namespace benignware\micro {
  function app() {
    return (new class {
      private $_params = [];
      private $_base_url = null;
      private $_routes = [];
      private $_middleware = [];
      private $_options = [];
    
      public function __construct() {
        $this->_base_url = sprintf(
          "%s://%s%s",
          isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
          $_SERVER['HTTP_HOST'],
          rtrim(pathinfo($_SERVER['PHP_SELF'])['dirname'], '/')
        );
      }
    
      public function __call($method, $arguments) {
        if (in_array($method, [ 'get', 'post', 'put', 'update', 'delete' ])) {
          list($path, $action) = $arguments;
    
          $this->_routes[] = (object) array(
            'path' => $path,
            'action' => $action,
            'method' => strtoupper($method)
          );
        }
      }
    
      public function set($name, $value) {
        $this->_options[$name] = $value;
      }
    
      public function use($func) {
        $this->_middleware[] = $func->bindTo($this);
      }
    
      public function render($template, $options = []) {
        $content = null;
    
        $options = array_merge(
          $this->_options,
          $options
        );
    
        foreach($options as $key => $value) {
          $$key = $options[$key];
        }
    
        if (file_exists($template)) {
          ob_start();
          $result = require($template);
          $content = ob_get_contents();
          ob_end_clean();
        }
    
        if ($options['layout'] && file_exists($options['layout'])) {
          $content = $this->render($options['layout'], array_merge(
            $options, [
              'layout' => false,
              'content' => $content
            ]
          ));
        }
    
        return $content;
      }
    
      public function url($path, $params = []) {
        $query = count(array_keys($params)) > 0 ? '?' . http_build_query($params) : '';
        $url = strpos($path, '/') === 0 ? $path : $this->_base_url . $path;

        return $url . $query;
      }
    
      public function redirect($url, $params = []) {
        header("Location: {$this->url($url, $params)}");
        exit;
      }
    
      public function __get($name) {
        switch($name) {
          case 'base_url':
            return $this->_base_url;
          default:
            user_error("Invalid property: " . __CLASS__ . "->$name");
        }
      }
    
      private function match($request) {
        $valid_chars = '.a-zA-Z0-9_-';
    
        foreach ($this->_routes as $route) {
          $path = rtrim($route->path, '/');
          $method = $route->method;
    
          if ($method !== $request->method) {
            continue;
          }
    
          $names = [];
          $values = [];
    
          $pattern_opt = '~\(\/\:([' . $valid_chars . ']+)\)~';
    
          $pattern_both = '~(?:\(\/)?\:([' . $valid_chars . ']+)(?:\))?~';
          $pattern = '~\:([' . $valid_chars . ']+)~';
          $pattern_x = preg_quote($pattern, '~');
    
          preg_match_all($pattern_both, $path, $names, PREG_PATTERN_ORDER);
    
          $match_pattern = $path;
          $match_pattern = preg_replace($pattern_opt, '(?:/([' . $valid_chars . ']+))?', $match_pattern);
          $match_pattern = preg_replace($pattern, '/([' . $valid_chars . ']+)', $match_pattern);
          $match_pattern = preg_replace('~\/+~', '/', $match_pattern);
          $match_pattern = '~^' . $match_pattern . '$~';

          $path = rtrim($request->path, '/');
    
          $match = preg_match(
            $match_pattern,
            $path,
            $values
          );
    
          if ($match) {
            array_shift($values);
            $names = $names[1];
            $values = array_merge($values, array_fill(count($values), count($names) - count($values), ''));
            $params = array_combine($names, $values);
            $params = array_merge(get_object_vars($request->params), $params);
    
            return (object) array_merge(
              get_object_vars($request),
              get_object_vars($route),
              array(
                'path' => $request->path,
                'params' => $params
              )
            );
          }
        }
    
        return null;
      }
    
      public function bootstrap() {
        $request = (object) [
          'path' => rtrim(strtok($_SERVER['REQUEST_URI'], '?'), '/') ?: '/',
          'method' => $_SERVER['REQUEST_METHOD'],
          'query' => $_SERVER['QUERY_STRING'],
          'params' => (object) array_merge($_GET, $_POST)
        ];

        $request = (object) array_merge(
          get_object_vars($request),
          get_object_vars($this->match($request) ?: new \stdClass())
        );

        $response = new \stdClass();
        $response->status = 200;
        $response->body = '';

        $this->request = $request;
        $this->response = $response;
    
        foreach ($this->_middleware as $fn) {
          $fn($request);
        }
    
        $result = '';

        if ($request) {
          if ($request->action) {
            if (is_callable($request->action)) {
              $action = $request->action->bindTo($this);
              $result = $action($request, $response);
            } else if (is_string($request->action)) {
              $result = $this->render($request->action);
            }
          }
        }

        if (is_numeric($result)) {
          $response->status = $result;
        } else if (is_string($result)) {
          $response->body = $result;
        }

        echo $response->body;
      }
    });
  }
}