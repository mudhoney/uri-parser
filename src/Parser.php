<?php
/**
 * League.Uri (http://uri.thephpleague.com).
 *
 * @package    League\Uri
 * @subpackage League\Uri\Parser
 * @author     Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license    https://github.com/thephpleague/uri-parser/blob/master/LICENSE (MIT License)
 * @version    2.0.0
 * @link       https://github.com/thephpleague/uri-parser/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace League\Uri;

/**
 * A class to parse a URI string according to RFC3986.
 *
 * @internal
 *
 * use the League\Uri\parse and League\Uri\is_host functions instead
 *
 * @see     https://tools.ietf.org/html/rfc3986
 * @package League\Uri
 * @author  Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @since   0.1.0
 */
final class Parser
{
    /**
     * @internal
     *
     * default URI component values
     */
    const URI_COMPONENTS = [
        'scheme' => null, 'user' => null, 'pass' => null, 'host' => null,
        'port' => null, 'path' => '', 'query' => null, 'fragment' => null,
    ];

    /**
     * @internal
     *
     * simple URI which do not need any parsing
     */
    const URI_SCHORTCUTS = [
        '' => [],
        '#' => ['fragment' => ''],
        '?' => ['query' => ''],
        '?#' => ['query' => '', 'fragment' => ''],
        '/' => ['path' => '/'],
        '//' => ['host' => ''],
    ];

    /**
     * @internal
     *
     * range of invalid characters in URI string
     */
    const REGEXP_INVALID_URI_CHARS = '/[\x00-\x1f\x7f]/';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#appendix-B
     *
     * RFC3986 regular expression URI splitter
     */
    const REGEXP_URI_PARTS = ',^
        (?<scheme>(?<scontent>[^:/?\#]+):)?    # URI scheme component
        (?<authority>//(?<acontent>[^/?\#]*))? # URI authority part
        (?<path>[^?\#]*)                       # URI path component
        (?<query>\?(?<qcontent>[^\#]*))?       # URI query component
        (?<fragment>\#(?<fcontent>.*))?        # URI fragment component
    ,x';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.1
     *
     * URI scheme regular expresssion
     */
    const REGEXP_URI_SCHEME = '/^([a-z][a-z\+\.\-]*)?$/i';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * IPvFuture regular expression
     */
    const REGEXP_IP_FUTURE = '/^
        v(?<version>[A-F0-9])+\.
        (?:
            (?<unreserved>[a-z0-9_~\-\.])|
            (?<sub_delims>[!$&\'()*+,;=:])  # also include the : character
        )+
    $/ix';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * General registered name regular expression
     */
    const REGEXP_REGISTERED_NAME = '/(?(DEFINE)
        (?<unreserved>[a-z0-9_~\-])   # . is missing as it is used to separate labels
        (?<sub_delims>[!$&\'()*+,;=])
        (?<encoded>%[A-F0-9]{2})
        (?<reg_name>(?:(?&unreserved)|(?&sub_delims)|(?&encoded))*)
    )
    ^(?:(?&reg_name)\.)*(?&reg_name)\.?$/ix';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * invalid characters in host regular expression
     */
    const REGEXP_INVALID_HOST_CHARS = '/
        [:\/?#\[\]@ ]  # gen-delims characters as well as the space character
    /ix';

    /**
     * @internal
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.3
     *
     * invalid path for URI without scheme and authority regular expression
     */
    const REGEXP_INVALID_PATH = ',^(([^/]*):)(.*)?/,';

    /**
     * @internal
     *
     * Host and Port splitter regular expression
     */
    const REGEXP_HOST_PORT = ',^(?<host>\[.*\]|[^:]+)(:(?<port>.*))?$,';

    /**
     * @internal
     *
     * IDN Host detector regular expression
     */
    const REGEXP_IDN_PATTERN = '/[^\x20-\x7f]/';

    /**
     * @internal
     *
     * Only the address block fe80::/10 can have a Zone ID attach to
     * let's detect the link local significant 10 bits
     */
    const ZONE_ID_ADDRESS_BLOCK = "\xfe\x80";

    /**
     * Parse an URI string into its components.
     *
     * This method parses a URI and returns an associative array containing any
     * of the various components of the URI that are present.
     *
     * <code>
     * $components = (new Parser())->parse('http://foo@test.example.com:42?query#');
     * var_export($components);
     * //will display
     * array(
     *   'scheme' => 'http',           // the URI scheme component
     *   'user' => 'foo',              // the URI user component
     *   'pass' => null,               // the URI pass component
     *   'host' => 'test.example.com', // the URI host component
     *   'port' => 42,                 // the URI port component
     *   'path' => '',                 // the URI path component
     *   'query' => 'query',           // the URI query component
     *   'fragment' => '',             // the URI fragment component
     * );
     * </code>
     *
     * The returned array is similar to PHP's parse_url return value with the following
     * differences:
     *
     * <ul>
     * <li>All components are always present in the returned array</li>
     * <li>Empty and undefined component are treated differently. And empty component is
     *   set to the empty string while an undefined component is set to the `null` value.</li>
     * <li>The path component is never undefined</li>
     * <li>The method parses the URI following the RFC3986 rules but you are still
     *   required to validate the returned components against its related scheme specific rules.</li>
     * </ul>
     *
     * @see https://tools.ietf.org/html/rfc3986
     *
     * @param mixed $uri any scalar or stringable object
     *
     * @throws Exception if the URI contains invalid characters
     * @throws Exception if the URI contains an invalid scheme
     * @throws Exception if the URI contains an invalid path
     *
     * @return array
     */
    public function parse($uri): array
    {
        if (!\is_scalar($uri) && !\method_exists($uri, '__toString')) {
            throw new \TypeError(\sprintf('The uri must be a scalar or a stringable object `%s` given', \gettype($uri)));
        }

        $uri = (string) $uri;

        if (isset(self::URI_SCHORTCUTS[$uri])) {
            return \array_merge(self::URI_COMPONENTS, self::URI_SCHORTCUTS[$uri]);
        }

        if (\preg_match(self::REGEXP_INVALID_URI_CHARS, $uri)) {
            throw new Exception(\sprintf('The uri `%s` contains invalid characters', $uri));
        }

        //if the first character is a known URI delimiter parsing can be simplified
        $first_char = $uri[0];

        //The URI is made of the fragment only
        if ('#' === $first_char) {
            $components = self::URI_COMPONENTS;
            list(, $components['fragment']) = \explode('#', $uri, 2);

            return $components;
        }

        //The URI is made of the query and fragment
        if ('?' === $first_char) {
            $components = self::URI_COMPONENTS;
            list(, $partial) = \explode('?', $uri, 2);
            list($components['query'], $components['fragment']) = \explode('#', $partial, 2) + [1 => null];

            return $components;
        }

        //use RFC3986 URI regexp to split the URI
        \preg_match(self::REGEXP_URI_PARTS, $uri, $parts);
        $parts += ['query' => '', 'fragment' => ''];

        if (':' === $parts['scheme'] || !\preg_match(self::REGEXP_URI_SCHEME, $parts['scontent'])) {
            throw new Exception(\sprintf('The uri `%s` contains an invalid scheme', $uri));
        }

        if ('' === $parts['scheme'].$parts['authority'] && \preg_match(self::REGEXP_INVALID_PATH, $parts['path'])) {
            throw new Exception(\sprintf('The uri `%s` contains an invalid path.', $uri));
        }

        return \array_merge(
            self::URI_COMPONENTS,
            '' === $parts['authority'] ? [] : $this->parseAuthority($parts['acontent']),
            [
                'path' => $parts['path'],
                'scheme' => '' === $parts['scheme'] ? null : $parts['scontent'],
                'query' => '' === $parts['query'] ? null : $parts['qcontent'],
                'fragment' => '' === $parts['fragment'] ? null : $parts['fcontent'],
            ]
        );
    }

    /**
     * Parse the URI authority part.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2
     *
     * @param string $authority
     *
     * @throws Exception If the URI authority part is invalid
     *
     * @return array
     */
    private function parseAuthority(string $authority): array
    {
        $components = ['host' => ''];
        if ('' === $authority) {
            return $components;
        }

        $parts = \explode('@', $authority, 2);
        if (isset($parts[1])) {
            list($components['user'], $components['pass']) = \explode(':', $parts[0], 2) + [1 => null];
        }

        \preg_match(self::REGEXP_HOST_PORT, $parts[1] ?? $parts[0], $matches);
        $matches += ['port' => ''];
        $port = '' === $matches['port'] ? null : \filter_var($matches['port'], \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false !== $port && $this->isHost($matches['host'])) {
            $components['port'] = $port;
            $components['host'] = $matches['host'];

            return $components;
        }

        throw new Exception(\sprintf('The URI authority `%s` is invalid', $authority));
    }

    /**
     * Returns whether a hostname is valid.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @return bool
     */
    public function isHost(string $host): bool
    {
        return '' === $host || $this->isIpHost($host) || $this->isRegisteredName($host);
    }

    /**
     * Validate a IPv6/IPvfuture host.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     * @see http://tools.ietf.org/html/rfc6874#section-2
     * @see http://tools.ietf.org/html/rfc6874#section-4
     *
     * @param string $host
     *
     * @return bool
     */
    private function isIpHost(string $host): bool
    {
        if ('[' !== $host[0] || ']' !== \substr($host, -1)) {
            return false;
        }

        $ip = \substr($host, 1, -1);
        if (\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return true;
        }

        if (\preg_match(self::REGEXP_IP_FUTURE, $ip, $matches)) {
            return !\in_array($matches['version'], ['4', '6'], true);
        }

        if (false === ($pos = \strpos($ip, '%'))) {
            return false;
        }

        if (\preg_match(self::REGEXP_INVALID_HOST_CHARS, \rawurldecode(\substr($ip, $pos)))) {
            return false;
        }

        $ip = \substr($ip, 0, $pos);
        if (!\filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            return false;
        }

        return \substr(\inet_pton($ip) & self::ZONE_ID_ADDRESS_BLOCK, 0, 2) === self::ZONE_ID_ADDRESS_BLOCK;
    }

    /**
     * Returns whether the host is an IPv4 or a registered named.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-3.2.2
     *
     * @param string $host
     *
     * @throws MissingIdnSupport if the registered name contains non-ASCII characters
     *                           and IDN support or ICU requirement are not available or met.
     *
     * @return bool
     */
    private function isRegisteredName(string $host): bool
    {
        $host = \rawurldecode($host);
        if (\preg_match(self::REGEXP_REGISTERED_NAME, $host)) {
            return true;
        }

        //to test IDN host non-ascii characters must be present in the host
        if (!\preg_match(self::REGEXP_IDN_PATTERN, $host)) {
            return false;
        }

        static $idn_support = null;
        $idn_support = $idn_support ?? \function_exists('\idn_to_ascii') && \defined('\INTL_IDNA_VARIANT_UTS46');
        if ($idn_support) {
            \idn_to_ascii($host, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46, $arr);

            return 0 === $arr['errors'];
        }

        // @codeCoverageIgnoreStart
        // added because it is not possible in travis to disabled the ext/intl extension
        // see travis issue https://github.com/travis-ci/travis-ci/issues/4701
        throw new MissingIdnSupport(\sprintf('the host `%s` could not be processed for IDN. Verify that ext/intl is installed for IDN support and that ICU is at least version 4.6.', $host));
        // @codeCoverageIgnoreEnd
    }
}
