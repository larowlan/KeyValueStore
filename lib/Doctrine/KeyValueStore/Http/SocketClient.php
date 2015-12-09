<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\KeyValueStore\Http;

/**
 * This class uses a custom HTTP client, which may have more bugs then the
 * default PHP HTTP clients, but supports keep alive connections without any
 * extension dependencies.
 *
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.com
 * @since       1.0
 * @author      Kore Nordmann <kore@arbitracker.org>
 * @deprecated  This class is deprecated and will be removed in 2.0.
 */
class SocketClient implements Client
{
    /**
     * Connection pointer for connections, once keep alive is working on the
     * server side.
     *
     * @var resource
     */
    protected $connection;

    /**
     * @var array
     */
    private $options = array(
        'keep-alive' => true,
    );

    public function __construct(array $options = array())
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * Check for server connection
     *
     * Checks if the connection already has been established, or tries to
     * establish the connection, if not done yet.
     *
     * @return void
     */
    protected function checkConnection($host, $port)
    {
        $host = ($port == 443) ? "ssl://" . $host : "tcp://" . $host;

        // If the connection could not be established, fsockopen sadly does not
        // only return false (as documented), but also always issues a warning.
        if (($this->connection === null) &&
            (($this->connection = @stream_socket_client($host . ":" . $port, $errno, $errstr)) === false)) {
            // This is a bit hackisch...
            $this->connection = null;
            throw new \RuntimeException("fail");
        }
    }

    /**
     * Build a HTTP 1.1 request
     *
     * Build the HTTP 1.1 request headers from the gicven input.
     *
     * @param string $method
     * @param string $path
     * @param string $data
     * @return string
     */
    protected function buildRequest($method, $url, $data, $headers)
    {
        $parts = parse_url($url);
        $host  = $parts['host'];
        $path  = $parts['path'];

        // Create basic request headers
        $request = "$method $path HTTP/1.1\r\nHost: {$host}\r\n";

        // Set keep-alive header, which helps to keep to connection
        // initilization costs low, especially when the database server is not
        // available in the locale net.
        $request .= "Connection: " . ( $this->options['keep-alive'] ? 'Keep-Alive' : 'Close' ) . "\r\n";

        // Also add headers and request body if data should be sent to the
        // server. Otherwise just add the closing mark for the header section
        // of the request.
        foreach ($headers as $name => $header) {
            $request .= $name . ": " . $header . "\r\n";
        }
        $request = rtrim($request) . "\r\n\r\n";

        if ($data !== null) {
            $request .= $data;
        }

        return $request;
    }

    /**
     * Perform a request to the server and return the result
     *
     * Perform a request to the server and return the result converted into a
     * Response object. If you do not expect a JSON structure, which
     * could be converted in such a response object, set the forth parameter to
     * true, and you get a response object retuerned, containing the raw body.
     *
     * @param string $method
     * @param string $path
     * @param string $data
     * @param bool $raw
     * @return Response
     */
    public function request($method, $url, $data = null, array $headers = array())
    {
        // Try establishing the connection to the server
        $parts = parse_url($url);
        $host  = $parts['host'];
        $this->checkConnection($host, $parts['scheme']=='https' ? 443 : 80);

        // Send the build request to the server
        if (fwrite($this->connection, $request = $this->buildRequest($method, $url, $data, $headers)) === false) {
            // Reestablish which seems to have been aborted
            //
            // The recursion in this method might be problematic if the
            // connection establishing mechanism does not correctly throw an
            // exception on failure.
            $this->connection = null;
            return $this->request($method, $url, $data, $headers);
        }

        // Read server response headers
        $rawHeaders = '';
        $headers    = array(
            'connection' => ( $this->options['keep-alive'] ? 'Keep-Alive' : 'Close' ),
        );

        // Remove leading newlines, should not accur at all, actually.
        while (true) {
            if (!(($line = fgets($this->connection)) !== false) || !(($lineContent = rtrim($line)) === '')) {
                break;
            }
        }

        // Throw exception, if connection has been aborted by the server, and
        // leave handling to the user for now.
        if ($line === false) {
            // Reestablish which seems to have been aborted
            //
            // The recursion in this method might be problematic if the
            // connection establishing mechanism does not correctly throw an
            // exception on failure.
            //
            // An aborted connection seems to happen here on long running
            // requests, which cause a connection timeout at server side.
            $this->connection = null;
            return $this->request($method, $url, $data, $raw);
        }

        do {
            // Also store raw headers for later logging
            $rawHeaders .= $lineContent . "\n";

            // Extract header values
            if (preg_match('(^HTTP/(?P<version>\d+\.\d+)\s+(?P<status>\d+))S', $lineContent, $match)) {
                $headers['version'] = $match['version'];
                $headers['status']  = (int) $match['status'];
            } else {
                list($key, $value)         = explode(':', $lineContent, 2);
                $headers[strtolower($key)] = ltrim($value);
            }
        } while ((($line = fgets($this->connection)) !== false) &&
                   (($lineContent = rtrim($line)) !== ''));

        // Read response body
        $body = '';
        if (!isset($headers['transfer-encoding']) ||
             ($headers['transfer-encoding'] !== 'chunked')) {
            // HTTP 1.1 supports chunked transfer encoding, if the according
            // header is not set, just read the specified amount of bytes.
            $bytesToRead = (int) ( isset( $headers['content-length'] ) ? $headers['content-length'] : 0 );

            // Read body only as specified by chunk sizes, everything else
            // are just footnotes, which are not relevant for us.
            while ($bytesToRead > 0) {
                $body .= $read = fgets($this->connection, $bytesToRead + 1);
                $bytesToRead  -= strlen($read);
            }
        } else {
            // When transfer-encoding=chunked has been specified in the
            // response headers, read all chunks and sum them up to the body,
            // until the server has finished. Ignore all additional HTTP
            // options after that.
            do {
                $line = rtrim(fgets($this->connection));

                // Get bytes to read, with option appending comment
                if (preg_match('(^([0-9a-fA-F]+)(?:;.*)?$)', $line, $match)) {
                    $bytesToRead = hexdec($match[1]);

                    // Read body only as specified by chunk sizes, everything else
                    // are just footnotes, which are not relevant for us.
                    $bytesLeft = $bytesToRead;
                    while ($bytesLeft > 0) {
                        $body .= $read = fread($this->connection, $bytesLeft + 2);
                        $bytesLeft    -= strlen($read);
                    }
                }
            } while ($bytesToRead > 0);

            // Chop off \r\n from the end.
            $body = substr($body, 0, -2);
        }

        // Reset the connection if the server asks for it.
        if ($headers['connection'] !== 'Keep-Alive') {
            fclose($this->connection);
            $this->connection = null;
        }

        // Handle some response state as special cases
        switch ($headers['status']) {
            case 301:
            case 302:
            case 303:
            case 307:
                return $this->request($method, $headers['location'], $data, $raw);
        }

        return new Response($headers['status'], $body, $headers);
    }
}
