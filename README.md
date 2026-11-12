# Vocabulary Library

An [Omeka S](https://omeka.org/s/) module for finding and importing RDF
vocabularies.

Omeka describes resources with RDF vocabularies, and they are getting harder
to find and to rely on. Publishers move or take down their files, and the
registries that have kept vocabularies findable are no longer reliably
maintained. The Vocabulary Library bundles more than 300 vocabularies with
the module, each tested with the Omeka S importer, so they stay available to
Omeka sites whatever happens to their publishers' servers. They range from
general ones such as Dublin Core and Schema.org to those for museums,
libraries, archives, the humanities, and the sciences.

# For administrators

The library is under **Modules > Vocabulary Library** in the admin menu, and
Omeka's **Import new vocabulary** page links to it. Global Administrators and
Supervisors can use it.

## Finding and importing

Filter the list by tag, by label language, or by whether a vocabulary's
namespace is already in use in Omeka, and search it by name, summary, prefix,
or namespace. Check **Also search class and property names** to find
vocabularies by their terms. Select a vocabulary's name to see its details,
and **Import** to import it.

Each class and property is imported with its `skos:definition` as its
comment, where it has one, and otherwise its `rdfs:comment` or description.
Many vocabularies define their terms only in `skos:definition`, which Omeka's
own import doesn't read.

## Namespaces already in use

**Namespace in use** means that Omeka already has a vocabulary with this
namespace, so this one cannot be imported. It can be compared and updated.

**Namespace in use as** means that Omeka has the namespace written another
way: with `http` rather than `https`, in different capitals, or with a
different closing `/` or `#`. That vocabulary cannot be imported or updated
from the library. To update it:

1. Under **Vocabularies**, edit the vocabulary and change its namespace to
   the library's.
2. Return to the library, where it can now be compared and updated.

Changing the namespace changes the addresses of the vocabulary's classes and
properties everywhere Omeka publishes them. Values stay attached to their
properties.

## Comparing and updating

**Compare and update**, in the details of a vocabulary whose namespace is in
use, shows how the vocabulary in Omeka differs from the library's file, and
**Update** applies the differences. Updating adds classes and properties and
replaces the labels and comments of existing ones with the file's, so look
over the changes first. It never removes a class or property.

Where the file has several languages, or its definitions differ from its
comments, the page offers a choice of how to read it. It starts with the
choice that matches the vocabulary in Omeka, so only real changes show.

Dublin Core and Dublin Core Type cannot be updated. Supervisors can update
only the vocabularies they own.

The library does not list which vocabularies need updating, because it cannot
tell whether its file or the vocabulary in Omeka is newer. The release notes
for each version of the module list the vocabularies whose files changed.

## Server memory

The largest files need more memory than PHP's default limit of 128 MB. The
library raises the limit to 256 MB where the server allows it. If the
details, import, or comparison of a large vocabulary fails, raise PHP's
`memory_limit`.

## About the collection

A vocabulary is included when an RDF file for it is available, its license
allows redistribution, and Omeka imports classes or properties from it that
are useful for describing resources. Thesauri and subject headings are not
included, because Omeka imports classes and properties, not concepts.

Titles and descriptions are the publisher's, taken from the file. The library
adds a few things of its own, shown separately from them:

- **A name**, where the file's title is missing, not in English, long, or
  leaves out the name the vocabulary is known by. It is quoted from the file
  or the publisher's documentation.
- **A one-sentence summary** of what the classes and properties describe,
  drafted with AI from the file and the publisher's documentation, checked
  claim by claim against them, and reviewed.
- **Tags**, one to three, for filtering by subject or use.

More than a quarter of the files are archived copies, used because the
publisher's own file cannot be downloaded or does not import. They come from
[Linked Open Vocabularies (LOV)](https://lov.linkeddata.es/),
[DBpedia Archivo](https://archivo.dbpedia.org/), the
[Open Metadata Registry](http://metadataregistry.org/), and
[Ontology Design Patterns](http://ontologydesignpatterns.org/).

To request a vocabulary, or to submit one of your own, open an issue in this
repository with its name and its RDF file or the file's address. A vocabulary
needs a namespace but not an address of its own. The Omeka team decides what
is included.

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

The collection is maintained with the scripts in `scripts/`, which are left
out of release packages. See [scripts/README.md](scripts/README.md).

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
