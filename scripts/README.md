# Maintaining the collection

The scripts here add, download, check, and remove the library's vocabularies.
They are left out of release packages. They use the Omeka S installation the
module is in, for its RDF importer. To use another, set `OMEKA_DIR`.

## Files

- `scripts/sources.json`: one entry per vocabulary, keyed by slug, with the
  curated fields: `download`, `namespace`, `prefix`, `summary`,
  `summary_basis` (what the summary was drafted from), and `tags`, and where
  needed `alt_title` with `alt_title_source` (where the name is quoted from),
  `alternates` (other download addresses to try), `format`, `copy_from`, and
  `notes`. A file sent to the library has `submitted_by` and `submitted_on`
  in place of `download`.
- `scripts/excluded.json`: vocabularies left out or removed, and why. The
  build refuses their slugs and namespaces, so they stay out.
- `data/vocabs/<slug>.<ext>`: the file the module imports.
- `data/vocabularies.json` and `data/term-index.json`: what the module reads,
  written by `build.php` from the sources and the files. Do not edit them.
- `config/module.config.php`: the tags.

## Scripts

The comment at the top of each script gives its usage and options in full.

- `add.php [<url>] --tags= --summary=`: adds a vocabulary from the address of
  its file. Options cover a vocabulary published only as RDFa in a web page,
  one published as a list of terms, an archived copy, and a file sent to the
  library, which has no address.
- `update.php [<slug> …]`: downloads every vocabulary again, or only the
  slugs given, and rebuilds. A downloaded file replaces the stored one only
  if it differs and still imports. The report says how each changed
  vocabulary differs, or that only its file does, and sets apart the
  failures that need a look from servers that are unavailable for now. Entries with `copy_from` or with no
  `download` are not downloaded. To try the publisher's file again, remove
  the `copy_from` field and run `update.php` for that slug; if the download
  fails, the archived copy remains, so restore the field.
- `remove.php <slug> "<reason>"`: removes a vocabulary, for example after a
  takedown request, and records it in `excluded.json`.
- `build.php`: checks every file with the Omeka S importer and writes what
  the module reads. It writes nothing if any entry has a problem, listing
  each one. The scripts above run it themselves; run it after editing
  `sources.json` by hand.
- `versions.php [<slug> …] [--drafts]`: lists what each file says about its
  version and date, and flags those that call themselves a draft, beta, or
  experimental version.

## Curation rules

- **Inclusion:** the vocabulary must be valid RDF, as a file or at an
  address. Omeka must import classes or properties from it that are useful
  for describing resources, and the license must not forbid redistribution;
  most files state none. A file sent to the library, with a namespace but no
  address, is included on the same terms. Whether a vocabulary belongs is the
  Omeka team's judgement. Record a vocabulary left out, and why, in
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
  against them. Say whether the vocabulary has classes, properties, or
  both, which the build checks; most summaries open with "Classes and
  properties", "Properties", or "Classes". No judgments, history, versions,
  or claims about the publisher.
- **Tags:** one to three, for what an administrator filtering by the tag would
  want, not for fields the vocabulary is only loosely related to.

## Releasing

Run `update.php`, then review the diff and commit. List in the release notes
the vocabularies whose terms changed, as `update.php` reports them, so that
administrators know which vocabularies to compare, and check each one's
summary against its new file. Slugs are permanent once released, because they
are in the module's URLs.
