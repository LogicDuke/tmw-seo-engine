<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Service {
    private $repository;

    public function __construct(TMW_Cluster_Repository $repository) {
        $this->repository = $repository;
    }

    public function get_cluster($id) {
        // TODO: Add service-layer business logic for single cluster retrieval.
        return $this->repository->get_cluster($id);
    }

    public function get_cluster_by_slug($slug) {
        // TODO: Add service-layer business logic for single cluster retrieval by slug.
        return $this->repository->get_cluster_by_slug($slug);
    }

    public function list_clusters($args = []) {
        // TODO: Add service-layer business logic for cluster listing.
        return $this->repository->get_clusters($args);
    }


    public function get_cluster_keywords($cluster_id, $args = []) {
        // TODO: Add service-layer business logic for cluster keyword retrieval.
        return $this->repository->get_cluster_keywords($cluster_id, $args);
    }

    public function get_cluster_pages($cluster_id, $args = []) {
        // TODO: Add service-layer business logic for cluster page retrieval.
        return $this->repository->get_cluster_pages($cluster_id, $args);
    }

    public function create_cluster($data) {
        // TODO: Add service-layer business logic for cluster creation.
        return $this->repository->create_cluster($data);
    }

    public function update_cluster($id, $data) {
        // TODO: Add service-layer business logic for cluster updates.
        return $this->repository->update_cluster($id, $data);
    }

    public function delete_cluster($id) {
        // TODO: Add service-layer business logic for cluster deletion.
        return $this->repository->delete_cluster($id);
    }
}
