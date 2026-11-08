# Importing a test from text

Admin → **Tests → Import from text**. Pick Listening or Reading, paste the whole test, and click outside the box.
The page lists problems with their line number, shows the questions with their answers, and asks for any pictures.
**Import** saves the test as an unpublished draft and opens it. Add the recordings (Listening) on that page, then publish.

A test can only be published when it has 4 parts (Listening) or 3 sections (Reading), 40 questions,
an answer for every question, every picture, and every recording.

## The structure

```
# Part 1                      ← Listening: "# Part 1" to "# Part 4"
Title: Booking a bike tour    ← optional

# Section 1                   ← Reading: "# Section 1" to "# Section 3"
## Text: Greenfield Community Pool
Subtitle: Opening times and rules      ← optional, right after the title

[A] First paragraph. "[A]" is the paragraph letter, only needed for matching tasks.

[B] Paragraphs are separated by an empty line.

Questions 1-5                 ← starts a block of questions. Numbers are checked.
Type: notes                   ← required, see the list below
Instructions: Complete the notes below.                  ← optional
Word limit: Write ONE WORD AND/OR A NUMBER for each answer.   ← optional
Title: Riverside Bike Tours   ← optional heading shown above the questions
```

Answers always go in double brackets: `[[Okafor]]`. Several accepted answers are separated with `|`: `[[colour|color]]`.
Question numbers are worked out automatically; the numbers you type are only used to check nothing is missing.

## The types

| Type line | Use for | How to write the questions |
|---|---|---|
| `Type: notes` / `form` / `sentences` / `summary` / `flow-chart` | completing notes, forms, sentences, summaries, flow charts | Write the text with the answers in place: `Phone: [[0775 318 204]]`. Lines starting with `# ` are sub-headings, `- ` are bullet points. |
| `Type: table` | table completion | First line = column headings, then one row per line, cells separated by `|`: `Monday | [[kayaking]] | £20` |
| `Type: choice` | multiple choice | `5. Why is Daniel calling?` then the options `A) …` `B) …`. Put `*` before the right one: `*B) to change a date`. For "Choose TWO", number it `14-15.` and mark two options. |
| `Type: tfng` / `ynng` | TRUE/FALSE/NOT GIVEN, YES/NO/NOT GIVEN | `1. The pool opens earlier on Saturdays. [[FALSE]]` |
| `Type: headings` | matching headings | `Options:` on its own line, then `i) …` `ii) …`, then `4. Paragraph A [[iii]]` |
| `Type: matching` / `matching-information` / `matching-features` / `sentence-endings` | other matching tasks | Same as headings with letters: `Options:`, `A) …`, then `12. The night tour [[B]]`. Add `Reuse: yes` if a letter can be used more than once, `Options title: …` for the list's heading. |
| `Type: map` | map or plan labelling with letters on the picture | `[image: short description of the picture]`, `Letters: 6` (how many letters are on it), then `7. Bike hire [[C]]` |
| `Type: diagram` | diagram labelling where students write words | `[image: …]`, then `11. [[valve]]`. Optional position of the marker: `11. [[valve]] @ 40,60` (% from left, % from top). |
| `Type: short-answer` | short answers | `6. Who must stay with young children? [[an adult|adult]]` |

### Word bank (summary with a list of words)

```
Questions 20-22
Type: summary
The centre was built in [[B]] and now offers [[D]] for local families.
Options:
A) 1990
B) 2004
C) concerts
D) classes
```
The answer can be the letter or the word itself.

## Full example (made up)

```
# Part 1
Title: Booking a bike tour

Questions 1-4
Type: form
Word limit: Write ONE WORD AND/OR A NUMBER for each answer.

Name: Daniel [[Okafor]]
Phone: [[0775 318 204|0775318204]]
Tour day: [[Thursday]]

Questions 5-6
Type: choice
5. Why is Daniel calling?
A) to cancel a tour
*B) to change a date
C) to ask about prices
```

More complete samples: `tests/Fixtures/import-listening-sample.txt` and `tests/Fixtures/import-reading-sample.txt`.

## Tips

- Copy the text from the source, then add the `#`, `Questions`, `Type:` lines and the `[[answers]]`. The page tells you which line is wrong.
- Red problems block the import (something would be misread). Yellow ones only stop publishing.
- Writing and Speaking tasks are short: enter them with **New test** as before.
