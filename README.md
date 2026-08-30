# GML Translation Core

Shared, product-neutral translation code for GML Translate and the translation
module bundled with GML SEO.

This is a build-time source package, not a WordPress plugin. End users never
install it. Each product release vendors the locked Core files into its own ZIP
and remains independently installable without Composer, npm, Git submodules, or
a third runtime plugin.

## Boundaries

Core owns translation state, storage schema, translation memory, safe AI
transport, translation prompts, bounded queue processing, manual editor logic,
bounded content crawling, translated HTML caching, parsing, glossary and
exclusion logic, language/URL utilities, and read-only translated URL
relationships.

Runtime lookups load only translation hashes used by the current page. Core
does not place an entire language dictionary into PHP or a persistent object
cache during ordinary frontend requests.

Core must not register product menus, decide which plugin owns SEO output, read
product-specific settings pages, or emit competing canonical/hreflang/sitemap
markup. Product adapters supply credentials and product state, select the SEO
authority, and expose the appropriate WordPress administration experience.

Legacy `GML_*` class names, `gml_*` options, and database tables are retained so
existing installations and rollback releases keep working.

## Vendoring

Product repositories contain `translation-core.lock.json` plus a vendoring
tool. The lock records this package version, source commit, and SHA-256 hash for
every shipped Core file. CI verifies both the committed vendor directory and,
when checked out, this exact source commit.
