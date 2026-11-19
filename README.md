# Vocabulary Library

An [Omeka S](https://omeka.org/s/) module for finding and importing RDF
vocabularies.

Omeka describes resources with RDF vocabularies, and they are getting harder
to find and to rely on. Publishers move or take down their files, and the
registries that have kept vocabularies findable are no longer reliably
maintained. The Vocabulary Library bundles more than 250 vocabularies with
the module, each tested with the Omeka S importer, so they stay available to
Omeka sites whatever happens to their publishers' servers. They range from
general-purpose vocabularies to those for museums, libraries, archives, the
humanities, and the sciences.

This page has a part for each kind of reader:

- [Administrators](#for-administrators), who use the library in Omeka.
- [Vocabulary publishers](#for-vocabulary-publishers), whose vocabularies are
  in the library or could be.
- [Developers](#for-developers), who maintain the module and its collection.

# For administrators

Install it as any module (see
[Installing modules](https://omeka.org/s/docs/user-manual/modules/#installing-modules)
in the Omeka S user manual). It needs Omeka S 4.3.0 or later.

The library is under **Modules > Vocabulary Library** in the admin menu, and
Omeka's **Import new vocabulary** page links to it. Global Administrators and
Supervisors can use it.

## Finding and importing

Filter the list by tag, by label language, or by whether a vocabulary is
already imported, and search it by name, summary, tag, prefix, or
namespace. Check **Also search class and property names** to find
vocabularies by their terms. Select a vocabulary's name to see its details,
and **Import** to import it. Import opens Omeka's import details, filled in
from the library's vocabulary: its name, summary, prefix, and namespace, and
the language the library is filtered by, where the vocabulary has it;
otherwise Omeka chooses the language, as for any file. Check them, change any
as needed, and import; importing leads to the vocabulary's page in Omeka.

## Vocabularies already imported

**Imported** means that Omeka already has a vocabulary with this one's
namespace, whatever file it came from, so this one cannot be imported again.
Its changes can be reviewed and applied instead, as described below.

A vocabulary whose namespace in Omeka differs slightly from the library's,
`http` for `https` or a different closing `/` or `#`, counts as imported too.
Its details say so, and updating it also changes its namespace to the
library's. This changes the addresses of its classes and properties
everywhere Omeka publishes them. Values stay attached to their properties.

## Reviewing changes

**Review changes**, in the details of an imported vocabulary, opens Omeka's
review of how the library's vocabulary differs from Omeka's, and **Accept
changes** applies the differences. Updating adds classes and properties and
changes the labels and comments of existing ones to match the library's
vocabulary, so look over the changes first. It never removes a class or
property. Accepting or cancelling leads to the vocabulary's page in Omeka,
as any review does.

Where the library's vocabulary has several languages, or gives its terms both
definitions and comments, the review offers a choice of which to compare. It
starts with the choice Omeka's vocabulary was imported with, or else the
closest, so in most cases only real changes show.

Dublin Core and Dublin Core Type cannot be updated. Supervisors can update
only the vocabularies they own.

The library does not list which vocabularies need updating, because it cannot
tell whether its vocabulary or Omeka's is newer. The release notes for each
version of the module list the vocabularies that changed.

## Server memory

The largest files need more memory than PHP's default limit of 128 MB.
Omeka raises the limit to 256 MB while reading a vocabulary's file, where the
server allows it. If the details, import, or comparison of a large vocabulary
fails, raise PHP's `memory_limit`.

## About the library

The library adds a few things of its own to what the files say:

- **A name**, in place of the file's title where that is missing, not in
  English, long, or leaves out the name the vocabulary is known by. It is
  quoted from the file or the publisher's documentation.
- **A one-sentence summary** of what the classes and properties describe,
  drafted with AI from the file and the publisher's documentation, checked
  claim by claim against them, and reviewed.
- **Tags**, one to three, for filtering by subject or use.

Thesauri, subject headings and code lists aren't included, because their
terms are values, not the classes and properties that Omeka imports.

To request a vocabulary, open an issue in this repository with its name and
where it is published. What a vocabulary must meet, and what is usually left
out, is under [Submitting a vocabulary](#submitting-a-vocabulary).

# For vocabulary publishers

## Submitting a vocabulary

To submit a vocabulary of your own, open an issue in this repository with its
name and the vocabulary, either as a file or as the address of one. A
vocabulary needs a namespace but not an address of its own.

A vocabulary must be valid RDF with classes or properties that Omeka can
import, and its license must not forbid redistribution. So thesauri, subject
headings and code lists, whose terms are values rather than classes and
properties, aren't included, and neither is a vocabulary with no RDF file, a
namespace that isn't a valid address, or labels longer than Omeka allows.

Beyond that, the Omeka team decides what is included. A vocabulary is usually
left out when:

- it describes something other than resources, such as other vocabularies,
  standards, or a system's internals;
- it is one collection's or one system's local fields, or a few properties
  that Dublin Core or a vocabulary already in the library covers;
- its properties are too fine-grained to choose from in Omeka, such as one for
  every field, indicator and subfield of a cataloguing format;
- its publisher has replaced or withdrawn it.

These are recommendations, each of which makes a vocabulary more useful in
Omeka:

- **A namespace that will not change,** not even from `http` to `https`.
  Omeka identifies a vocabulary by its namespace: under a new one it is a
  different vocabulary, and sites that imported the old one can update from
  the new only by changing its namespace.
- **A stable address for the file,** if it has one, so that each release can
  include your current version.
- **A stated license.**
- **A title for the vocabulary, in the file.**
- **A label and a definition for every class and property, with language
  tags.**

Labels are read from `skos:prefLabel`, or otherwise `rdfs:label`, and
comments from `skos:definition`, or otherwise `rdfs:comment`. Where a term has
neither, a few other common properties are read, such as
`dcterms:description` for comments.

## Vocabularies in the library

Files are redistributed without changes to what they say. A vocabulary
published only within a web page, or as a list of terms each at its own
address, is stored as one file of the same statements. For each release, the
library downloads each vocabulary from its publisher again, where it can.
More than a quarter of the files are archived copies from public vocabulary
archives, used because the publisher's own file cannot be downloaded or does
not import. If your vocabulary has moved, or the library's name or summary
for it is wrong, open an issue in this repository.

If a vocabulary you publish is in the library and you want it removed, open an
issue or contact the Omeka team through the
[forum](https://forum.omeka.org/). It will be removed in the next release.

# For developers

The collection is maintained with the scripts in `scripts/`, which are left
out of release packages. See [scripts/README.md](scripts/README.md).

The tests are in `test/`, also left out of release packages. They use Omeka's
own test framework and need its development dependencies. The database tests
also need the test database set in Omeka's
`application/test/config/database.ini`, and they drop every table in it.

From Omeka's root, to run all the tests:

    vendor/bin/phpunit -c modules/VocabularyLibrary/test/phpunit.xml

To run only those that need no database:

    vendor/bin/phpunit -c modules/VocabularyLibrary/test/phpunit.xml --testsuite unit,script

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
