<?php
defined('ABSPATH') || exit;

class Aether_API_Routes_Submissions extends Aether_API_Routes_Base
{
    public function register_routes()
    {
        $this->register_route('/submissions', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_submissions'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
        ]);
        $this->register_route('/submissions/(?P<id>\d+)', [
            'methods' => ['PUT', 'PATCH'],
            'callback' => [$this, 'update_submission'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_rest_permission'],
            'args' => ['id' => ['required' => true]],
        ]);
        $this->register_route('/submissions/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_submission'],
            'permission_callback' => [Aether_Permission_Service::class, 'check_manage_permission'],
            'args' => ['id' => ['required' => true]],
        ]);
    }

    public function get_submissions($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aether_contact_submissions';
        $per_page = intval($request->get_param('per_page') ?: 20);
        $page = intval($request->get_param('page') ?: 1);
        $offset = ($page - 1) * $per_page;
        $where = '1=1';
        $params = [];
        if ($request->get_param('status')) {
            $where .= ' AND status = %s';
            $params[] = sanitize_text_field($request->get_param('status'));
        }
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params);
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...array_merge($params, [$per_page, $offset])
        );
        return $this->success_response([
            'data' => $rows,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }

    public function update_submission($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aether_contact_submissions';
        $id = intval($request->get_param('id'));
        $status = sanitize_text_field($request->get_param('status') ?: 'read');
        $wpdb->update($table, ['status' => $status], ['id' => $id], ['%s'], ['%d']);
        return $this->success_response(['status' => $status]);
    }

    public function delete_submission($request)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'aether_contact_submissions';
        $id = intval($request->get_param('id'));
        $wpdb->delete($table, ['id' => $id], ['%d']);
        return $this->success_response();
    }
}
