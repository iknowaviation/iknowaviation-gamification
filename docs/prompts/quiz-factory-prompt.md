You are generating quizzes for the iKnowAviation platform.

You MUST strictly follow the canonical ChatGPT Quiz Import JSON schema
defined in:
ika-chatgpt-schema-PRODUCTION-BASE_v2.json

GLOBAL RULES (DO NOT VIOLATE):
- Output VALID JSON ONLY (no markdown, no commentary).
- ASCII characters only (no smart quotes, em dashes, emojis, symbols).
- Each quiz must contain EXACTLY 8 questions.
- Use concept-first, beginner-friendly explanations.
- Explanations must follow IKA Quiz Explanation Standards:
  - 2–4 sentences
  - calm instructor tone
  - concept-first (why > rule)
  - no test-prep language
  - no FARs, equations, or fear language
- Each question must have exactly ONE correct answer.
- Titles must NOT be numbered.
- Titles must include a clear descriptive subtitle.

BATCH REQUIREMENTS:
- Generate EXACTLY 8 quizzes in a single JSON file.
- All quizzes must belong to:
  Group: {GROUP}
  Level: {LEVEL}
  Track: {TRACK}
- Audience defaults to aviation-curious unless otherwise specified.
- menu_order must increment by 10 per quiz (10, 20, 30, ...).

TITLE FORMAT:
"{Group Short Name}: {Descriptive Subtitle}"

TAXONOMY RULES:
- Use wp.tax with canonical keys only:
  ika_quiz_group
  ika_quiz_track
  ika_quiz_topic
  ika_quiz_difficulty
  ika_quiz_audience

QUESTIONS:
- 8 questions per quiz
- Plain-English wording
- Distractors must represent common misconceptions
- Avoid duplicate question framing across quizzes

OUTPUT:
- One valid JSON object
- schema_version = "1.1"
- version = "1.0"
- quizzes[] array populated

NOW GENERATE:
Group: {GROUP}
Level: {LEVEL}
Track: {TRACK}
Starting menu_order: {START}
Topics per quiz should be logical and non-overlapping.
