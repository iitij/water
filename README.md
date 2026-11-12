## مخططات المعمارية ودورة العمل

[المخطط المتكامل لمشروع Water](docs/architecture/WATER_SYSTEM_ARCHITECTURE_AR.md) — مخططات Mermaid للعلاقات المعمارية، ودورة عمل المشترك والعداد، ورحلة الموظف، والمزامنة، ونموذج البيانات.

**العداد الواحد لكل مشترك:** [قرار التصميم والترحيل الآمن واختبارات القبول](docs/architecture/SINGLE_CURRENT_METER_CHANGE_AR.md). يظل أي ترحيل لقاعدة البيانات الفعلية مشروطاً بفحص التعارضات وحفظ البيانات.

## ملاحظات الإصدار المؤجلة إلى ما قبل الإنتاج

المشروع ما زال في **مرحلة التطوير** وليس منشوراً كإصدار إنتاجي. مراجعة 2026-11-12 والمهام الـ12 الناتجة عنها موثقة في [تقرير الفحص وسجل إصلاحات ما قبل الإنتاج](docs/v2/qa/PREPRODUCTION_AUDIT_2026-11-12_AR.md)، وفي [سجل مهام الفريق](docs/v2/agents/backlog.json) بالمعرّفات `PREPROD-01` إلى `PREPROD-12`، وعلى [GitHub Issues](https://github.com/5krm/water/issues?q=PREPROD). **الإصلاحات مؤجلة بقرار صاحب المشروع؛ لا تعتبر أي منها منجزة أو مقبولة للإنتاج حتى تستوفي شروط القبول.**

## Mobile / Windows application

The Flutter app is in [`mobile app/`](mobile%20app/README.md). Laravel remains at
the repository root. See the app README for build commands, first-launch server
setup and testing-release limitations. The master plan is a specification, not
proof that production acceptance tests have passed.

### Release 2.0 plan

The full plan for the second release (Arabic, 65 pages: current-state audit,
goals, UX, architecture, data/sync, security, backend contracts, testing,
release, 12-week roadmap, budget, risks and acceptance gates) is in:

- [`docs/V2_MOBILE_PLAN_AR.pdf`](docs/V2_MOBILE_PLAN_AR.pdf) — printable edition
- [`docs/V2_MOBILE_PLAN_AR.docx`](docs/V2_MOBILE_PLAN_AR.docx) — editable edition
- [`docs/V2_MOBILE_PLAN_AR.md`](docs/V2_MOBILE_PLAN_AR.md) — Markdown edition

All three are generated from `docs/v2/content_*.py`; edit the content modules and
rebuild (see `docs/v2/README.md`) instead of editing the outputs.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
