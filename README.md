# Vocabulary Library

An [Omeka S](https://omeka.org/s/) module with a library of RDF vocabularies
tested to import correctly.

Importing a vocabulary into Omeka S requires its file and its exact namespace
URI. A namespace that is slightly off, such as one missing a trailing `#`,
imports nothing. The library provides both for each vocabulary, tested with
the Omeka S importer.

## Using the library

The library is under **Modules > Vocabulary Library** in the admin menu, and
**Vocabularies > Import new vocabulary** links to it. Global Administrators
and Supervisors can use it.

Filter the list by tag or label language, or search it, optionally including
class and property names. Select a vocabulary's title to see its details and
download its file. Select **Import** to import it. The import form sets the
prefix, label, and comment, and the **Preferred language** when the file has
more than one language. The prefix cannot be changed after import.

**Namespace in use** means that Omeka already has a vocabulary with this
namespace, so this one cannot be imported. The vocabulary in Omeka may be a
different version, or in another language. **Namespace in use as** means that
Omeka has the namespace in another form, such as `http://schema.org/` for
`https://schema.org/`. That vocabulary cannot be imported or updated from the
library.

### Comparing and updating

For a vocabulary marked Namespace in use, **Compare and update** in its
details shows how the vocabulary in Omeka differs from the library's file, and
**Update** applies the differences. Updating adds classes and properties and
changes the labels and comments of existing ones, but never removes a class or
property. Terms listed under **Not in the library's file** can mean that the
vocabulary in Omeka is newer. Update only when the library's file is newer.

When the file has more than one language, set **Preferred language** to the
language the vocabulary was imported in, or to **First available** if it was
imported without one. Another language shows what switching its labels and
comments to that language would change, which can correct a vocabulary
imported with labels in mixed languages.

Dublin Core and Dublin Core Type cannot be updated. Supervisors can update
only the vocabularies they own.

The library does not list which vocabularies need updating. It cannot tell
whether the library's file or the vocabulary in Omeka is newer, or which
language a vocabulary was imported in, so each vocabulary is compared on its
own. The release notes for each version of the module list the vocabularies
whose files changed.

The largest files need more memory than PHP's default limit of 128 MB: RDA
Agent properties needs about 160 MB to compare. The library raises the limit
to 256 MB where the server allows it. If the details, import, or comparison
of a large vocabulary fails, raise PHP's `memory_limit`.

## About the collection

The library holds vocabularies for describing resources in digital
collections, and for rights, files, measurement, and provenance. A vocabulary
is included when an RDF file for it is available and Omeka imports classes or
properties from it that are useful for describing resources. Thesauri and
subject headings are not included, because Omeka imports classes and
properties, not concepts.

Titles and descriptions are the publisher's, taken from the file. The library
adds two things of its own, shown separately from them:

- **An alternative title**, where the file has no title, none in English, or
  one that hides or leaves out the name the vocabulary is known by. It is
  another official name for the vocabulary, quoted from the file or the
  publisher's documentation, and the file's title is still shown. It keeps
  or adds the vocabulary's acronym, as in "Simple Knowledge Organization
  System (SKOS)", but only one the file or the publisher uses. One that
  shortens a long title only cuts from it: a publisher's name, a version
  that distinguishes nothing, or a descriptive subtitle after a name that
  stands on its own.
- **A one-sentence summary** of every vocabulary, saying what its classes and
  properties describe. The summaries were drafted with AI from each
  vocabulary's file and the publisher's documentation, checked claim by
  claim against them, and reviewed.

Vocabulary files move, disappear, or stop importing as publishers change their
sites. The files are bundled with the module, so importing a vocabulary does
not depend on its publisher's server. About a third of the files are archived
copies, used because the publisher's file cannot be downloaded or does not
import. They come from
[Linked Open Vocabularies (LOV)](https://lov.linkeddata.es/),
[DBpedia Archivo](https://archivo.dbpedia.org/), the
[Open Metadata Registry](http://metadataregistry.org/), and
[Ontology Design Patterns](http://ontologydesignpatterns.org/).

### Licensing and takedown

Files are redistributed without changes to their content, as LOV and Archivo
do. Darwin Core and Audiovisual Core are published one term at a time, so
their files combine the publisher's description of each term, unchanged. Most
files state no license. Vocabularies whose license forbids redistribution
are not included.

If a vocabulary you publish is in the library and you want it removed, open an
issue in this repository or contact the Omeka team through the
[forum](https://forum.omeka.org/). It will be removed in the next release.

### Requesting a vocabulary

Open an issue in this repository with the vocabulary's name and the address
of its RDF file.

## Maintaining the collection

The scripts in `scripts/` maintain the collection, and are left out of release
packages. They use the Omeka S installation the module is in. To use another,
set `OMEKA_DIR`.

The collection is kept in these files:

- `scripts/sources.json`: one entry per vocabulary, keyed by slug, with the
  curated fields: `download`, `namespace`, `prefix`, `summary`,
  `summary_basis` (what the summary was drafted from), and `tags` (one to
  three, from `config/module.config.php`), and where needed `alt_title` with
  `alt_title_source` (where the name is quoted from), `alternates`, `format`,
  `copy_from`, and `notes`.
- `scripts/excluded.json`: vocabularies left out or removed, and why.
- `data/vocabs/<slug>.<ext>`: the file the module imports.
- `data/vocabularies.json` and `data/term-index.json`: what the module reads,
  written by `build.php` from the sources and the files. They should not be
  edited.

To add a vocabulary:

    php scripts/add.php <url> --tags=<tag>[,<tag>…] --summary= [--prefix=] [--slug=] [--namespace=] [--alt-title= --alt-title-source=] [--format=rdfa|term-list]

Use `--format=rdfa` for a vocabulary published only as RDFa in a web page,
such as VRA. Use `--format=term-list` for one whose address serves only a
list of its terms, each described at its own address, such as Darwin Core;
every term is fetched. Both are stored as N-Triples. For an archived copy, also give
`--file=<path> --copy-from=<archive>`, where `<archive>` is one of the four
sources above, such as `--copy-from="DBpedia Archivo"`.

To download every vocabulary again, or only the slugs given, and rebuild:

    php scripts/update.php [<slug> …]

A downloaded file replaces the stored one only if it differs and still
imports. Entries with `copy_from` are not downloaded. To try the publisher's
file again, remove the field and run `update.php` for that slug. If the
download fails, the archived copy remains, so restore the field.

To remove a vocabulary, for example after a takedown request:

    php scripts/remove.php <slug> "<reason>"

These scripts run `build.php` themselves. After editing
`scripts/sources.json` by hand, run `php scripts/build.php`. Then review the
diff and commit. For each release, list in the release notes the vocabularies
whose files changed, as `update.php` reports them, so that administrators know
which vocabularies to compare, and check each one's summary against its new
file. Slugs are permanent once released, because they
are in the module's URLs. A vocabulary in `excluded.json` stays out: the build
refuses its slug and its namespace.

To list what each file says about its version and date, and flag those that
call themselves a draft, beta, or experimental version:

    php scripts/versions.php [<slug> …] [--drafts]

# Copyright

Vocabulary Library is Copyright © 2026-present Corporation for Digital
Scholarship, Vienna, Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code under
the GNU General Public License, version 3 (GPLv3). The full text of this license
is given in the license file.

The vocabulary files in `data/vocabs/` are the work of their publishers and
are distributed under their own terms, where stated.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

Third-party copyright in this distribution is noted where applicable.

All rights not expressly granted are reserved.
