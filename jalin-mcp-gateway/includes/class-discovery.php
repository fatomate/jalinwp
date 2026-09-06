<?php
/** Publish public OAuth metadata for hosts that serve .well-known before WordPress. */
defined('ABSPATH') || exit;

final class FG_Discovery {
    private const FILES_OPTION = 'fg_oauth_discovery_files';
    private const LOCK_OPTION = 'fg_oauth_discovery_lock';

    /** Call from the authorized plugin setup/diagnostic UI; never from public OAuth requests. */
    public static function publish(): array {
        if (!current_user_can('manage_options')) { return self::result('skipped', 'Only an administrator can prepare OAuth discovery.'); }
        $settings = FG_Core::settings();
        if (empty($settings['enabled']) || empty($settings['oauth_enabled'])) { return self::result('skipped', 'Enable the gateway and OAuth to prepare discovery.'); }
        $root = self::webroot(ABSPATH, site_url('/'), (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($root === null) { return self::result('skipped', 'The site document root could not be confirmed. WordPress discovery routes remain available.'); }
        $lock = self::lock();
        if ($lock === null) { return self::result('skipped', 'OAuth discovery is being prepared by another request. Run Check connection again shortly.'); }
        try {
            // Publish both discovery entry points. A client may discover the resource
            // before sending an MCP request, so the 401 header is not its only path.
            // Each file is independent: a conflict must not prevent the other update.
            $definitions = [
                'authorization_server' => ['label' => 'Authorization server', 'identifier' => FG_OAuth::issuer(),
                    'target' => self::target(FG_OAuth::issuer(), site_url('/')), 'data' => FG_OAuth::authorization_metadata()],
                'protected_resource' => ['label' => 'Protected resource', 'identifier' => FG_OAuth::resource(),
                    'target' => self::resource_target(FG_OAuth::resource(), site_url('/')), 'data' => FG_OAuth::resource_metadata()],
            ];
            $documents = []; $messages = []; $status = 'ready';
            $priority = ['ready' => 0, 'skipped' => 1, 'error' => 2, 'conflict' => 3];
            foreach ($definitions as $name => $definition) {
                $target = $definition['target'];
                if ($target === null) {
                    $result = self::result('skipped', 'This site URL does not support automatic publication. WordPress discovery routes remain available.');
                } else {
                    // Public endpoint locations/capabilities only; never credentials.
                    $json = wp_json_encode($definition['data'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    $result = !is_string($json) || strlen($json) > 32768 ? self::result('error', 'The public discovery document could not be prepared.') :
                        self::write($root, $target, $json . "\n");
                }
                $result['url'] = $target === null ? '' : self::publication_url($definition['identifier'], $target);
                $documents[$name] = $result;
                $messages[] = $definition['label'] . ': ' . $result['message'];
                if ($priority[$result['status']] > $priority[$status]) { $status = $result['status']; }
            }
            return ['status' => $status, 'message' => implode(' ', $messages), 'documents' => $documents];
        }
        finally { self::unlock($lock); }
    }

    /** Standard RFC 9728 path for clients that discover before receiving a challenge. */
    public static function protected_resource_url(): string {
        $resource = FG_OAuth::resource();
        $target = self::resource_target($resource, site_url('/'));
        return $target === null ? '' : self::publication_url($resource, $target);
    }

    private static function publication_url(string $identifier, string $target): string {
        $parts = wp_parse_url($identifier);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/' . $target;
    }

    /** A root install is unambiguous. A subdirectory install additionally needs a matching document root. */
    private static function webroot(string $installation, string $site_url, string $document_root): ?string {
        $install = realpath($installation); $site = wp_parse_url($site_url);
        if ($install === false || !is_dir($install) || !is_array($site) || empty($site['host']) || isset($site['query']) || isset($site['fragment'])) { return null; }
        $path = rtrim((string) ($site['path'] ?? ''), '/');
        if ($path === '') { return $install; }
        if (!self::segments(ltrim($path, '/'))) { return null; }
        $root = $document_root !== '' ? realpath($document_root) : false;
        if ($root === false || !is_dir($root) || realpath($root . $path) !== $install) { return null; }
        return $root;
    }

    /** Derive the RFC 8414 path from a same-origin HTTPS issuer, never from request input. */
    private static function target(string $issuer, string $site_url): ?string {
        return self::identifier_target($issuer, $site_url, 'oauth-authorization-server', '/oauth/issuer.json');
    }

    /** The existing canonical MCP resource and audience remain unchanged. */
    private static function resource_target(string $resource, string $site_url): ?string {
        // This standard path is extensionless. Writing JSON does not control the
        // host's Content-Type; diagnostics must report MIME separately from reachability.
        // Never occupy /.well-known/oauth-protected-resource itself: other services
        // may use it, and this gateway needs that location to be a parent directory.
        return self::identifier_target($resource, $site_url, 'oauth-protected-resource', '/mcp');
    }

    private static function identifier_target(string $identifier, string $site_url, string $type, string $ending): ?string {
        $parts = wp_parse_url($identifier); $site = wp_parse_url($site_url);
        if (!is_array($parts) || !is_array($site) || ($parts['scheme'] ?? '') !== 'https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
            strtolower($parts['host']) !== strtolower((string) ($site['host'] ?? '')) || (int) ($parts['port'] ?? 443) !== (int) ($site['port'] ?? 443)) { return null; }
        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        if (!self::segments($path) || !str_ends_with($path, $ending)) { return null; }
        return '.well-known/' . $type . '/' . $path;
    }

    private static function segments(string $relative): bool {
        if ($relative === '' || strlen($relative) > 1024) { return false; }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._-]+$/D', $segment)) { return false; }
        }
        return true;
    }

    /** Every component is inspected individually; never follow a symlink within the publication path. */
    private static function directory(string $root, string $relative): ?string {
        $directory = $root;
        foreach (explode('/', $relative) as $part) {
            $next = $directory . '/' . $part;
            if (is_link($next)) { return null; }
            if (!file_exists($next)) {
                if (!self::writable($directory) || (!@mkdir($next, 0755) && !is_dir($next))) { return null; }
            }
            if (!is_dir($next) || is_link($next)) { return null; }
            $directory = $next;
        }
        return $directory;
    }

    private static function writable(string $path): bool {
        $permissions = @fileperms($path);
        return $permissions !== false && ($permissions & 0222) !== 0 && is_writable($path);
    }

    private static function write(string $root, string $relative, string $json): array {
        if (!self::segments($relative)) { return self::result('error', 'The OAuth discovery publication path is invalid.'); }
        $directory = self::directory($root, dirname($relative));
        if ($directory === null) { return self::result('error', 'The discovery directory is unavailable or protected. Existing files were left unchanged.'); }
        $path = $root . '/' . $relative;
        if (is_link($path) || (file_exists($path) && !is_file($path))) { return self::result('conflict', 'Another file or link occupies the discovery location. It was left unchanged.'); }
        $records = get_option(self::FILES_OPTION, []);
        if (!is_array($records)) { return self::result('conflict', 'The discovery ownership record is invalid. Existing files were left unchanged.'); }
        $key = hash('sha256', $root . "\n" . $relative); $hash = hash('sha256', $json);
        $record = $records[$key] ?? null; $exists = is_file($path);
        $old_hash = $exists ? @hash_file('sha256', $path) : false;
        if ($exists && (!is_array($record) || ($record['root'] ?? null) !== $root || ($record['path'] ?? null) !== $relative ||
            !is_string($old_hash) || !hash_equals((string) ($record['sha256'] ?? ''), $old_hash))) {
            return self::result('conflict', 'An existing discovery file belongs to another service or was edited outside this plugin. It was left unchanged.');
        }
        if ($exists && hash_equals($hash, $old_hash)) { return self::result('ready', 'The public OAuth discovery file is ready.'); }
        if (!self::writable($directory) || ($exists && !self::writable($path))) { return self::result('error', 'The public discovery file is not writable. Existing files were left unchanged.'); }
        if (count($records) >= 16 && !isset($records[$key])) { return self::result('error', 'The discovery ownership record is full. Existing files were left unchanged.'); }
        $old_json = $exists ? @file_get_contents($path, false, null, 0, 32770) : null;
        if ($exists && (!is_string($old_json) || strlen($old_json) > 32769 || hash('sha256', $old_json) !== $old_hash)) {
            return self::result('conflict', 'The discovery file changed while it was being prepared. It was left unchanged.');
        }

        $temporary = null; $stream = false;
        if ($exists) {
            // A same-directory exclusive temporary file permits an atomic replacement.
            for ($i = 0; $i < 3 && $stream === false; $i++) {
                $temporary = $directory . '/.fg-discovery-' . wp_generate_uuid4() . '.tmp';
                $stream = @fopen($temporary, 'x+b');
            }
        } else { $stream = @fopen($path, 'x+b'); }
        if ($stream === false) { return self::result('error', 'The public discovery file could not be created. Existing files were left unchanged.'); }
        $destination = $temporary ?? $path;
        $written = @fwrite($stream, $json); $flushed = @fflush($stream); fclose($stream);
        if ($written !== strlen($json) || !$flushed) {
            @unlink($destination);
            return self::result('error', 'The public discovery file could not be written completely. Try Check connection again.');
        }
        if ($exists) {
            // Recheck ownership just before replacing; the option lock serializes plugin requests.
            clearstatcache(true, $path);
            if (is_link($path) || !is_file($path) || @hash_file('sha256', $path) !== $old_hash) {
                @unlink($temporary);
                return self::result('conflict', 'The discovery file changed while it was being prepared. It was left unchanged.');
            }
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                return self::result('error', 'The public discovery file could not be updated. Existing files were left unchanged.');
            }
        }
        $records[$key] = ['root' => $root, 'path' => $relative, 'sha256' => $hash];
        update_option(self::FILES_OPTION, $records, false);
        if (get_option(self::FILES_OPTION, []) !== $records) {
            // Restore the previous owned bytes if persistence failed. Never replace a concurrent edit.
            if ($exists) {
                $restored = self::restore($path, $old_json, $hash);
                return self::result('error', $restored ? 'The discovery ownership record could not be saved. The previous document was restored; try Check connection again.' :
                    'The discovery ownership record could not be saved and the previous document could not be restored. Contact support before retrying publication.');
            }
            $removed = !is_link($path) && @hash_file('sha256', $path) === $hash && @unlink($path);
            return self::result('error', $removed ? 'The discovery ownership record could not be saved. The new document was removed; try Check connection again.' :
                'The discovery ownership record could not be saved and the new document could not be removed. Contact support before retrying publication.');
        }
        return self::result('ready', 'The public OAuth discovery file is ready.');
    }

    private static function restore(string $path, string $previous, string $current_hash): bool {
        $temporary = dirname($path) . '/.fg-discovery-' . wp_generate_uuid4() . '.tmp';
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) { return false; }
        $written = @fwrite($stream, $previous); $flushed = @fflush($stream); fclose($stream);
        clearstatcache(true, $path);
        $safe = $written === strlen($previous) && $flushed && !is_link($path) && is_file($path) && @hash_file('sha256', $path) === $current_hash;
        if ($safe && @rename($temporary, $path)) { return true; }
        @unlink($temporary);
        return false;
    }

    private static function lock(): ?string {
        $value = time() . ':' . wp_generate_uuid4();
        if (add_option(self::LOCK_OPTION, $value, '', false)) { return $value; }
        $old = get_option(self::LOCK_OPTION, '');
        if (is_string($old) && preg_match('/^([0-9]+):[a-f0-9-]+$/D', $old, $matches) && (int) $matches[1] < time() - 60) {
            self::unlock($old);
            if (add_option(self::LOCK_OPTION, $value, '', false)) { return $value; }
        }
        return null;
    }

    private static function unlock(string $value): void {
        global $wpdb;
        // Delete only our exact lock value; a concurrent successor keeps its own lock.
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", self::LOCK_OPTION, $value));
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    private static function result(string $status, string $message): array { return ['status' => $status, 'message' => $message]; }
}
