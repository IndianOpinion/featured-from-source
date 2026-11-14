<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromSource;

/**
 * REST routes for the meta box (case 17). Capability and nonce checks on
 * every route; no admin-ajax.
 */
final class Rest
{
    public const NAMESPACE = 'featured-from-source/v1';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/run', [
            'methods' => 'POST',
            'callback' => [self::class, 'run'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
                'force' => ['type' => 'boolean', 'default' => false],
                'dry_run' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => [self::class, 'canEdit'],
            'args' => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    /**
     * `edit_post` on the post and a valid REST nonce.
     *
     * @return true|\WP_Error
     */
    public static function canEdit(\WP_REST_Request $request)
    {
        $postId = (int) $request->get_param('id');
        $nonce = (string) $request->get_header('X-WP-Nonce');
        if ($nonce === '' || wp_verify_nonce($nonce, 'wp_rest') === false) {
            return new \WP_Error('ffs_bad_nonce', 'The security check failed. Reload the page and try again.', ['status' => 403]);
        }
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            return new \WP_Error('ffs_forbidden', 'You cannot edit this post.', ['status' => 403]);
        }
        return true;
    }

    public static function run(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $record = run_post($postId, [
            'force' => (bool) $request->get_param('force'),
            'dry_run' => (bool) $request->get_param('dry_run'),
        ]);
        return new \WP_REST_Response([
            'record' => $record->toArray(),
            'message' => Reason::describe($record->reason),
            'status' => Status::get($postId),
        ], 200);
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        $postId = (int) $request->get_param('id');
        $status = Status::get($postId);
        $reason = is_string($status['reason'] ?? null) ? $status['reason'] : '';
        return new \WP_REST_Response([
            'status' => $status,
            'message' => $reason === '' ? '' : Reason::describe($reason),
        ], 200);
    }
}
