# Copilot Coding Instructions

Instead of analyzing these 5 files one by one, please analyze them simultaneously and provide a combined JSON response for all, summarizing the dependency issues.

## Response Style
- Review this code for security vulnerabilities. Be extremely concise. Only output the line number, the vulnerability type, and a 1-sentence fix. Do not provide conversational filler.
- No conversational filler, introductory text, or concluding remarks.
- Provide only code, or code with concise inline comments.

## Code Quality
- Generate only syntactically valid PHP code, or Swift code, or markdown, depending on the file.
- Include input validation on all external data (user input, API responses, file contents)
- Escape outputs appropriately (SQL parameters, shell arguments, HTML)
- Maintain existing functionality — no silent behavioral changes.
- Improve readability where possible.
- Add concise comments for non-obvious logic only.
- Reduce complexity; prefer simple, direct solutions over abstractions.

## EXPLICIT EXCLUSIONS:
- No placeholder functions with "// TODO" implementations
- No generic boilerplate that ignores the specific use case
- No packages without specific version constraints
- No code that assumes "happy path" only
- Do not duplicate code for different devices if it can be avoided. Instead, use conditional compilation or helper functions to share code across devices while still allowing for device-specific differences when necessary.

## What NOT to Do
- **Don't check in private information** — no names, API keys, tokens, passwords, IP addresses, personal data, or any identifying information. This is a PUBLIC repo.
- Don't add npm dependencies without asking
- Don't create a build step
- Don't push without running tests
- Don't start implementing without plan approval