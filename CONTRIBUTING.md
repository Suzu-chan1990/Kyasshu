# Contributing to Kyasshu

Contributions are welcome.

## How to contribute
1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Submit a pull request

## Guidelines
- Follow WordPress coding standards where practical
- Keep performance in mind: Kyasshu runs on every request
- Avoid unnecessary dependencies
- Comments in English
- Write clear commit messages

## Reporting issues
Please include:
- WordPress version
- PHP version and whether `exec()` is allowed
- nginx version and the Kyasshu rules you use
- Output of `brotli --version` and `zstd --version`, if installed
- Steps to reproduce
- Lines from `kyasshu-debug.log` (switch on "Debug Modus")
