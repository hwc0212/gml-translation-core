<?php
/** Object-aware, read-only resource readiness contract. */
if ( ! defined( 'ABSPATH' ) ) exit;

interface GML_Resource_Translation_Provider_Interface {
    public function resolve_resource( $subject = null );
    public function get_resource_status( $subject, $lang );
    public function get_resource_statuses( $subject );
    public function get_resource_statuses_bulk( array $subjects, array $languages = [] );
    public function get_resource_alternate_candidates( $subject );
    public function get_resource_alternate_candidates_bulk( array $subjects );
}
