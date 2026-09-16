<?php

namespace App\Support;

/**
 * Validates module manifests (Modules slugs with module.json).
 * Detects: missing manifest, invalid JSON, duplicate slug,
 * missing dependency, circular dependency, invalid provider, bad entitlement/permission format.
 */
final class ModuleManifestValidator
{
    /**
     * Validate a single decoded manifest.
     *
     * @return list<string> errors (empty = valid)
     */
    public static function validate(array $manifest, string $source = ''): array
    {
        $errors = [];
        $prefix = $source !== '' ? "[{$source}] " : '';

        foreach (['name', 'slug', 'version'] as $required) {
            if (empty($manifest[$required]) || ! is_string($manifest[$required])) {
                $errors[] = "{$prefix}Missing required key: {$required}";
            }
        }

        if (isset($manifest['slug']) && is_string($manifest['slug']) && ! preg_match('/^[a-z0-9_.-]+$/', $manifest['slug'])) {
            $errors[] = "{$prefix}Slug must be lowercase alphanumeric with dashes/underscores/dots.";
        }

        if (isset($manifest['version']) && is_string($manifest['version']) && ! preg_match('/^\d+\.\d+\.\d+/', $manifest['version'])) {
            $errors[] = "{$prefix}Version must be semantic (e.g. 1.0.0).";
        }

        foreach (['dependencies', 'permissions', 'entitlements'] as $listKey) {
            if (isset($manifest[$listKey]) && ! is_array($manifest[$listKey])) {
                $errors[] = "{$prefix}{$listKey} must be an array.";
            }
        }

        if (isset($manifest['navigation']) && ! is_array($manifest['navigation'])) {
            $errors[] = "{$prefix}navigation must be an array.";
        }

        if (isset($manifest['navigation']) && is_array($manifest['navigation'])) {
            foreach ($manifest['navigation'] as $i => $item) {
                if (! is_array($item) || empty($item['label']) || empty($item['route'])) {
                    $errors[] = "{$prefix}navigation[{$i}] must contain label + route.";
                }
            }
        }

        foreach ($manifest['permissions'] ?? [] as $perm) {
            if (! is_string($perm) || ! preg_match('/^[a-z0-9_.-]+\.[a-z0-9_.-]+/i', $perm)) {
                $errors[] = "{$prefix}Invalid permission format: ".json_encode($perm);
            }
        }

        foreach ($manifest['entitlements'] ?? [] as $ent) {
            if (! is_string($ent) || ! preg_match('/^[a-z0-9_.-]+\.[a-z0-9_.-]+/i', $ent)) {
                $errors[] = "{$prefix}Unknown/invalid entitlement format: ".json_encode($ent);
            }
        }

        if (isset($manifest['provider'])) {
            if (! is_string($manifest['provider']) || ! class_exists($manifest['provider'])) {
                $errors[] = "{$prefix}Invalid provider class: ".json_encode($manifest['provider'] ?? null);
            }
        }

        return $errors;
    }

    /**
     * Validate a set of manifests keyed by slug.
     * Checks duplicate slugs, missing + circular dependencies.
     *
     * @param  array<string,array>  $manifests
     * @return list<string>
     */
    public static function validateSet(array $manifests): array
    {
        $errors = [];

        // Duplicate slug is a caller error (same key twice impossible) — detect slug mismatch instead.
        foreach ($manifests as $key => $m) {
            if (isset($m['slug']) && $m['slug'] !== $key) {
                $errors[] = "Slug mismatch: key [{$key}] vs manifest slug [{$m['slug']}].";
            }
        }

        // Missing dependencies.
        foreach ($manifests as $slug => $m) {
            foreach ($m['dependencies'] ?? [] as $dep) {
                if (! isset($manifests[$dep]) && ! self::isKnownExternal($dep, $manifests)) {
                    // Only flag if dep is not provided by DB/file set; caller merges DB slugs.
                    $errors[] = "Module [{$slug}] depends on missing module [{$dep}].";
                }
            }
        }

        // Circular dependency detection (DFS).
        $visited = [];
        $stack = [];
        $visit = function (string $slug) use (&$visit, &$visited, &$stack, &$errors, $manifests): void {
            $visited[$slug] = true;
            $stack[$slug] = true;
            foreach ($manifests[$slug]['dependencies'] ?? [] as $dep) {
                if (! isset($manifests[$dep])) {
                    continue;
                }
                if (! isset($visited[$dep])) {
                    $visit($dep);
                } elseif (isset($stack[$dep])) {
                    $errors[] = "Circular dependency detected: {$slug} <-> {$dep}.";
                }
            }
            unset($stack[$slug]);
        };

        foreach (array_keys($manifests) as $slug) {
            if (! isset($visited[$slug])) {
                $visit($slug);
            }
        }

        return $errors;
    }

    private static function isKnownExternal(string $dep, array $manifests): bool
    {
        return isset($manifests[$dep]);
    }

    /**
     * Load all manifests from the Modules directory.
     *
     * @return array{manifests: array<string,array>, errors: list<string>}
     */
    public static function loadFromDisk(string $basePath): array
    {
        $manifests = [];
        $errors = [];

        if (! is_dir($basePath)) {
            $errors[] = 'Missing Modules/ directory.';

            return compact('manifests', 'errors');
        }

        foreach (glob($basePath.'/*/module.json') ?: [] as $file) {
            $dir = basename(dirname($file));
            $raw = @file_get_contents($file);
            if ($raw === false) {
                $errors[] = "[{$dir}] missing/unreadable manifest.";

                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $errors[] = "[{$dir}] invalid JSON: ".json_last_error_msg();

                continue;
            }
            $slug = $decoded['slug'] ?? $dir;
            if (isset($manifests[$slug])) {
                $errors[] = "Duplicate slug [{$slug}] in {$file}.";

                continue;
            }
            $manifests[$slug] = $decoded;
            foreach (self::validate($decoded, $slug) as $e) {
                $errors[] = $e;
            }
        }

        foreach (self::validateSet($manifests) as $e) {
            $errors[] = $e;
        }

        return compact('manifests', 'errors');
    }
}
