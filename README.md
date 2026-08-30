# GML Translation Core

Shared, product-neutral translation code for GML Translate and the translation
module bundled with GML SEO.

This is a build-time source package, not a WordPress plugin. End users never
install it. Each product release vendors the locked Core files into its own ZIP
and remains independently installable without Composer, npm, Git submodules, or
a third runtime plugin.

## Boundaries

Core may own translation state, storage schema, parsing, glossary and exclusion
logic, language/URL utilities, and read-only translated URL relationships.

Core must not register product menus, decide which plugin owns SEO output, read
product-specific settings pages, or emit competing canonical/hreflang/sitemap
markup. Product adapters explicitly register all WordPress hooks.

Legacy `GML_*` class names, `gml_*` options, and database tables are retained so
existing installations and rollback releases keep working.

## Vendoring

Product repositories contain `translation-core.lock.json` plus a vendoring
tool. The lock records this package version, source commit, and SHA-256 hash for
every shipped Core file. CI verifies both the committed vendor directory and,
when checked out, this exact source commit.
