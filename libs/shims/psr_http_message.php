<?php
// libs/shims/psr_http_message.php
namespace Psr\Http\Message;

interface MessageInterface { /* minimal stub */ }
interface RequestInterface extends MessageInterface {}
interface ServerRequestInterface extends RequestInterface {}
interface ResponseInterface extends MessageInterface {}
interface StreamInterface {}
interface UriInterface {}