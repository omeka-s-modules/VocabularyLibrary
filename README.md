# Vocabulary Library

An [Omeka S](https://omeka.org/s/) module for finding and importing RDF
vocabularies.

Omeka describes resources with RDF vocabularies, and they are getting harder
to find and to rely on. Publishers move or take down their files, and the
registries that have kept vocabularies findable are no longer reliably
maintained. The Vocabulary Library bundles more than 300 vocabularies with
the module, each tested with the Omeka S importer, so they stay available to
Omeka sites whatever happens to their publishers' servers.

The vocabularies range from general ones such as Dublin Core and Schema.org
to those for museums, libraries, archives, the humanities, and the sciences.
Administrators can browse them by tag, search them and their classes and
properties, read a plain summary of each, and import one without looking for
its file or namespace. A vocabulary already in Omeka can be compared with the
library's file and updated.

# For administrators

The library is under **Modules > Vocabulary Library** in the admin menu, and
core's **Import new vocabulary** page links to it. Global Administrators and
Supervisors can use it.

## Finding a vocabulary

Filter the list by tag, by the languages of its labels, or by whether its
namespace is already in use in Omeka. Search matches names, titles,
summaries, prefixes, and namespaces. Check **Also search class and property
names** to find vocabularies by their terms: each row then lists its best
matches, and **All matching terms** opens the vocabulary's details filtered to
them.

Select a vocabulary's name to see its details: its status in Omeka, the
publisher's title and description, the library's summary, its namespace,
prefix, tags, label languages, and license, a link to download its file, and
its classes and properties, which can be filtered.

## Importing

Select **Import** to open the import form. It sets the prefix, label, and
comment, and the **Preferred language** when the file has more than one
language. It warns when the prefix is already used in Omeka or reserved. The
prefix cannot be changed after import.

Each class and property gets its `skos:definition` from the file as its
comment, where it has one, and otherwise its `rdfs:comment`, or failing that
its description. Many vocabularies define their terms only in
`skos:definition`, which Omeka's own import doesn't read, so the library
imports them with more of their comments filled in.

**Namespace in use** means that Omeka already has a vocabulary with this
namespace, so this one cannot be imported. The vocabulary in Omeka may be a
different version, or in another language. **Namespace in use as** means that
Omeka has the namespace written another way: with `http` rather than `https`,
in different capitals, or with a different closing `/` or `#`, such as
`http://schema.org/` for `https://schema.org/`. That vocabulary cannot be
imported or updated from the library. To update it, first change its namespace
in Omeka to the library's, by editing the vocabulary under **Vocabularies**.
This changes the addresses of its classes and properties everywhere Omeka
publishes them, though values stay attached to their properties. It can then
be compared and updated like any other vocabulary in use.

## Comparing and updating

For a vocabulary whose namespace is in use, **Compare and update** in its
details shows how the vocabulary in Omeka differs from the library's file, and
**Update** applies the differences. Updating adds classes and properties and
changes the labels and comments of existing ones, but never removes a class or
property. Terms listed under **Not in the library's file** are in Omeka but
not in the file, and updating keeps them. Look over the changes before
updating: the labels and comments in Omeka are replaced with the file's.

When the file has more than one language, set **Preferred language** to the
language the vocabulary was imported in, or to **First available** if it was
imported without one. Another language shows what switching its labels and
comments to that language would change, which can correct a vocabulary
imported with labels in mixed languages.

When the file's definitions and comments differ, **Comment property** chooses
between `skos:definition, else rdfs:comment`, as the library imports terms,
and `rdfs:comment`, as Omeka's own import does. It starts with the one that
matches the vocabulary in Omeka, so only real changes show. Choose the other
to see what switching its comments would change.

Dublin Core and Dublin Core Type cannot be updated. Supervisors can update
only the vocabularies they own.

The library does not list which vocabularies need updating. It cannot tell
whether the library's file or the vocabulary in Omeka is newer, or which
language a vocabulary was imported in, so each vocabulary is compared on its
own. The release notes for each version of the module list the vocabularies
whose files changed.

## Server memory

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
adds a few things of its own, shown separately from them:

- **A name**, where the file's title is missing, not in English, long, or
  leaves out the name the vocabulary is known by. It is quoted from the file
  or the publisher's documentation, sometimes shortened or with the
  vocabulary's acronym, and the file's title is still shown in the details.
- **A one-sentence summary** of what each vocabulary's classes and properties
  describe. The summaries were drafted with AI from each vocabulary's file and
  the publisher's documentation, checked claim by claim against them, and
  reviewed.
- **Tags**, one to three for each vocabulary, for filtering by subject or
  use.

More than a quarter of the files are archived copies, used because the
publisher's own file cannot be downloaded or does not import. They come from
[Linked Open Vocabularies (LOV)](https://lov.linkeddata.es/),
[DBpedia Archivo](https://archivo.dbpedia.org/), the
[Open Metadata Registry](http://metadataregistry.org/), and
[Ontology Design Patterns](http://ontologydesignpatterns.org/).

## Requesting a vocabulary

Open an issue in this repository with the vocabulary's name and the address
of its RDF file.

# For vocabulary publishers

Files are redistributed without changes to their content, as LOV and Archivo
do. Darwin Core and Audiovisual Core are published one term at a time, so
their files combine the publisher's description of each term, unchanged. Most
files state no license. Vocabularies whose license forbids redistribution are
not included.

For each release, the library downloads each vocabulary from its publisher
again, where it can. If your vocabulary has moved, or the library's name or
summary for it is wrong, open an issue in this repository.

If a vocabulary you publish is in the library and you want it removed, open an
issue or contact the Omeka team through the
[forum](https://forum.omeka.org/). It will be removed in the next release.

# For developers

The collection is maintained with the scripts in `scripts/`, which are left out
of release packages. They use the Omeka S installation the module is in, for
its RDF importer. To use another, set `OMEKA_DIR`.

## Files

- `scripts/sources.json`: one entry per vocabulary, keyed by slug, with the
  curated fields: `download`, `namespace`, `prefix`, `summary`,
  `summary_basis` (what the summary was drafted from), and `tags`, and where
  needed `alt_title` with `alt_title_source` (where the name is quoted from),
  `alternates` (other download addresses to try), `format`, `copy_from`, and
  `notes`.
- `scripts/excluded.json`: vocabularies left out or removed, and why. The
  build refuses their slugs and namespaces, so they stay out.
- `data/vocabs/<slug>.<ext>`: the file the module imports.
- `data/vocabularies.json` and `data/term-index.json`: what the module reads,
  written by `build.php` from the sources and the files. Do not edit them.
- `config/module.config.php`: the tags.

## Scripts

To add a vocabulary:

    php scripts/add.php <url> --tags=<tag>[,<tag>…] --summary= [--prefix=] [--slug=] [--namespace=] [--alt-title= --alt-title-source=] [--format=rdfa|term-list]

Use `--format=rdfa` for a vocabulary published only as RDFa in a web page,
such as VRA. Use `--format=term-list` for one whose address serves only a
list of its terms, each described at its own address, such as Darwin Core;
every term is fetched. Both are stored as N-Triples. For an archived copy,
also give `--file=<path> --copy-from=<archive>`, where `<archive>` is one of
the four sources above, such as `--copy-from="DBpedia Archivo"`.

To download every vocabulary again, or only the slugs given, and rebuild:

    php scripts/update.php [<slug> …]

A downloaded file replaces the stored one only if it differs and still
imports. Entries with `copy_from` are not downloaded. To try the publisher's
file again, remove the field and run `update.php` for that slug. If the
download fails, the archived copy remains, so restore the field.

To remove a vocabulary, for example after a takedown request:

    php scripts/remove.php <slug> "<reason>"

These scripts run `build.php` themselves. After editing
`scripts/sources.json` by hand, run `php scripts/build.php`. The build checks
every file with the Omeka S importer and writes nothing if any entry has a
problem, listing each one.

To list what each file says about its version and date, and flag those that
call themselves a draft, beta, or experimental version:

    php scripts/versions.php [<slug> …] [--drafts]

## Curation rules

- **Inclusion:** Omeka must import classes or properties from the file that
  are useful for describing resources, and the license must allow
  redistribution. Record a vocabulary left out, and why, in
  `excluded.json`.
- **Names:** add an `alt_title` only where the file's title is missing, not
  in English, long, or hides or leaves out the name the vocabulary is known
  by. Quote another official name from the file or the publisher's
  documentation, and record where in `alt_title_source`. Keep or add the
  vocabulary's acronym, as in "Simple Knowledge Organization System (SKOS)",
  but only one the file or the publisher uses. To shorten a long title, only
  cut from it: a publisher's name, a version that distinguishes nothing, or a
  descriptive subtitle after a name that stands on its own. Never reorder or
  compose.
- **Summaries:** one plain sentence of 25 words or fewer, saying what the
  classes and properties describe, drafted from the file and the publisher's
  documentation (recorded in `summary_basis`) and checked claim by claim
  against them. Open with "Classes and properties", "Properties", or
  "Classes", matching what the vocabulary has. No judgments, history,
  versions, or claims about the publisher.
- **Tags:** one to three, for what an administrator filtering by the tag would
  want, not for fields the vocabulary is only loosely related to.

## Releasing

Run `update.php`, then review the diff and commit. List in the release notes
the vocabularies whose files changed, as `update.php` reports them, so that
administrators know which vocabularies to compare, and check each one's
summary against its new file. Slugs are permanent once released, because they
are in the module's URLs.

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
