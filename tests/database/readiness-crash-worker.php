<?php
require __DIR__ . '/bootstrap.php';
gml_test_product_init();

add_action( 'gml_resource_readiness_batch_claimed', static function() {
    exit( 86 );
} );

GML_Resource_Readiness::run_rebuild_batch( 'crash-test' );
exit( 87 );
