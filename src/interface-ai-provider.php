<?php
/**
 * Shared AI provider contract.
 *
 * Provider adapters own credentials and prompts. Core transport owns safe HTTP,
 * bounded retries, error classification, and redaction.
 *
 * @package GML_Translation_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
interface GML_AI_Provider_Interface {
    public function supports( $capability );
    public function validate_credentials();
    public function generate( array $request );
    public function batch_generate( array $requests );
    public function get_model();
    public function get_last_error();
}
