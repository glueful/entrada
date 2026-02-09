# Changelog

All notable changes to the Entrada (Social Login & SSO) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Twitter/X OAuth integration
- LinkedIn professional authentication
- Discord social login support
- Two-factor authentication with social providers
- Social account activity monitoring and analytics

## [1.4.0] - 2026-02-09

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.30.0 (Diphda release)
- **Exception Imports**: Migrated from deleted legacy bridge class to modern exception namespace
  - `Glueful\Exceptions\HttpException` → `Glueful\Http\Exceptions\HttpException` in `GoogleAuthProvider`, `AppleAuthProvider`, `FacebookAuthProvider`, `GithubAuthProvider`
- **PHP 8.1+ Cleanup**: Removed redundant `setAccessible(true)` calls from `AppleAuthProvider` reflection usage (no-op since PHP 8.1)
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.30.0`, version bumped to `1.4.0`

### Notes
- No breaking changes to extension API. Import paths and reflection cleanup are internal.
- Requires Glueful Framework 1.30.0+ due to removal of legacy exception bridge classes.

## [1.3.1] - 2026-02-06

### Changed
- **Version Management**: Version is now read from `composer.json` at runtime via `EntradaServiceProvider::composerVersion()`.
  - `registerMeta()` in `boot()` now uses `self::composerVersion()` instead of a hardcoded string.
  - Future releases only require updating `composer.json` and `CHANGELOG.md`.

### Notes
- No breaking changes. Internal refactor only.

## [1.3.0] - 2026-02-05

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.28.0
  - Compatible with route caching infrastructure (Bellatrix release)
  - Routes converted from closures to `[Controller::class, 'method']` syntax for cache compatibility
  - New dedicated controllers: `SocialAuthController`, `SocialAccountController`
- **Route Refactoring**: All 14 OAuth routes now use controller syntax
  - Social authentication flows (Google, Facebook, GitHub, Apple)
  - Native app token exchange endpoints
  - Social account management (list, unlink)
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.28.0`

### Added
- **SocialAuthController**: Handles OAuth initialization, callbacks, and native token exchange
  - `googleInit`, `googleCallback`, `googleNative` for Google OAuth
  - `facebookInit`, `facebookCallback`, `facebookNative` for Facebook OAuth
  - `githubInit`, `githubCallback` for GitHub OAuth
  - `appleInit`, `appleCallback`, `appleNative` for Apple Sign-In
- **SocialAccountController**: Manages linked social accounts
  - `index` - List user's linked social accounts
  - `destroy` - Unlink a social account

### Notes
- This release enables route caching for improved performance
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [1.2.0] - 2026-01-31

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.22.0
  - Compatible with the new `ApplicationContext` dependency injection pattern
  - No code changes required in extension - framework handles context propagation
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.22.0`

### Notes
- This release ensures compatibility with Glueful Framework 1.22.0's context-based dependency injection
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [1.1.1] - 2026-01-24

### Changed
- **AbstractSocialProvider**: Improved database connection handling.
  - Database connection now injected via DI container in constructor.
  - Reuses single connection instance instead of creating new connections per method call.
  - Added `Connection` class property for better performance and testability.
- **Routes**: Updated database queries to use fluent query builder pattern.
  - Simplified database access using `container()->get('database')`.
  - Migrated from static QueryBuilder methods to fluent `$db->table()->select()->where()->get()` pattern.
  - Delete operations now use fluent builder for consistency.

### Performance
- Reduced database connection overhead by reusing connection instance in social providers.
- Consistent query builder usage improves query optimization.

### Notes
- No breaking changes. All endpoints maintain the same behavior.
- Compatible with Glueful Framework 1.19.x.

## [1.1.0] - 2026-01-17

### Breaking Changes
- **PHP 8.3 Required**: Minimum PHP version raised from 8.2 to 8.3.
- **Glueful 1.9.0 Required**: Minimum framework version raised to 1.9.0.

### Changed
- Updated `composer.json` PHP requirement to `^8.3`.
- Updated `extra.glueful.requires.glueful` to `>=1.9.0`.

### Notes
- Ensure your environment runs PHP 8.3 or higher before upgrading.
- Run `composer update` after upgrading.

## [1.0.0] - 2024-12-14

### Added
- **Migration to Modern Extension System**
  - Fully migrated to Glueful's new modern extension architecture
  - Implemented standardized extension metadata structure
  - Added proper service provider registration with EntradaServiceProvider
  - Integrated with extension discovery and auto-loading system
  - Added support for extension dependencies and version requirements
- **Comprehensive PHP Usage Documentation**
  - Controller examples for social login implementation
  - Direct provider usage patterns for all supported providers
  - Social account management code samples
  - Custom service integration examples
  - Middleware implementation for social authentication
  - Event listener examples for social login events
- **Enhanced Router Integration**
  - Updated to use new Glueful Router syntax with injected `$router` instance
  - Proper middleware chaining support
  - Consistent with framework conventions
  - Migrated from static Router calls to instance-based routing

### Changed
- **Extension Architecture**
  - Migrated from legacy extension system to modern architecture
  - Updated composer.json with `glueful-extension` type
  - Added proper `extra.glueful` metadata configuration
  - Implemented standardized directory structure (src/, config/, migrations/)
  - Updated service provider to extend new base classes
- **API Response Consistency**
  - All examples now use `Glueful\Http\Response` instead of Symfony Response
  - Standardized response methods across all code samples
  - Improved error handling patterns
- **Provider Implementation**
  - Fixed `generateUsername` method in GithubAuthProvider
  - Added proper fallback for username generation
  - Enhanced error handling in provider classes

### Fixed
- Resolved undefined method error in GithubAuthProvider
- Fixed missing Utils import in provider classes
- Corrected response type hints in documentation examples
- Updated route definitions to work with new router system

### Documentation
- Added extensive PHP usage examples to README
- Improved code samples with real-world scenarios
- Enhanced API documentation with practical examples
- Added event-driven architecture examples
- Documented migration to modern extension system

## [0.18.0] - 2024-06-21

### Added
- **Multi-Platform OAuth Support**
  - Google OAuth 2.0 with OpenID Connect
  - Facebook Graph API integration
  - GitHub OAuth with required scopes
  - Apple Sign In with advanced JWT validation
- **Dual Authentication Flows**
  - Web-based OAuth redirect flow for traditional applications
  - Native mobile token verification for mobile apps
  - Direct token validation endpoints for SPA applications
- **Advanced Apple Sign In Integration**
  - Custom ASN.1 JWT parser for Apple's signature validation
  - Private key JWT generation for client authentication
  - Support for Apple's unique first-login-only user data
  - Proxy email handling for privacy-focused users
- **Enterprise Security Features**
  - CSRF protection with state parameter validation
  - JWT token management with Glueful's TokenManager
  - Secure OAuth redirect URI validation
  - Provider token verification and expiry handling
- **Database Integration**
  - Comprehensive social accounts table with foreign keys
  - User-social account relationship management
  - Profile data synchronization and storage
  - Unique constraints to prevent duplicate associations

### Enhanced
- **User Management System**
  - Automatic user registration from social profiles
  - Intelligent account linking via email matching
  - Profile synchronization with configurable options
  - User creation with social provider metadata
- **Provider Abstraction**
  - Abstract provider pattern for consistent implementation
  - Factory pattern for dynamic provider instantiation
  - Standardized error handling across providers
  - Configurable scope management per provider
- **API Architecture**
  - RESTful endpoints with comprehensive OpenAPI documentation
  - Consistent response formats across all providers
  - Proper HTTP status code handling
  - Detailed error responses with debugging information

### Security
- Implemented comprehensive CSRF protection for OAuth flows
- Added secure token storage and validation mechanisms
- Enhanced provider token verification with timeout handling
- Secure configuration management with environment variables

### Performance
- Provider configurations and public keys are cached for performance
- HTTP clients use connection pooling for provider API calls
- Database queries optimized with proper indexing
- JWT validation results cached to reduce external API calls

### Developer Experience
- Complete API documentation with request/response examples
- Comprehensive error handling with detailed error messages
- Health monitoring endpoints for system diagnostics
- Extensive configuration options for customization

## [0.17.0] - 2024-04-30

### Added
- **Core OAuth Infrastructure**
  - OAuth 2.0 flow implementation for Google and Facebook
  - Basic GitHub OAuth integration
  - Initial Apple Sign In support
- **User Account Management**
  - Basic user registration from social profiles
  - Simple account linking functionality
  - Profile data extraction and storage
- **Database Foundation**
  - Social accounts table creation migration
  - Basic foreign key relationships
  - User-social account association logic

### Enhanced
- **Provider Management**
  - Configuration-based provider enabling/disabling
  - Environment variable configuration support
  - Basic error handling for failed authentications
- **Security Foundation**
  - Basic OAuth state parameter validation
  - Initial token verification implementation
  - Secure redirect URI handling

### Fixed
- OAuth callback URL handling inconsistencies
- Token expiry validation edge cases
- User profile data extraction errors

## [0.16.0] - 2024-03-15

### Added
- **Google OAuth Integration**
  - Complete Google OAuth 2.0 implementation
  - ID token verification with Google's public keys
  - User profile data extraction from Google People API
- **Facebook OAuth Integration**
  - Facebook Login integration with Graph API
  - Access token validation and user profile retrieval
  - Long-lived token support for extended sessions
- **Basic Security Features**
  - OAuth state parameter generation and validation
  - Basic CSRF protection for authentication flows
  - Secure token storage in session management

### Infrastructure
- Extension service provider registration
- Route definitions for OAuth endpoints
- Basic configuration management system
- Initial database migration for social accounts

## [0.15.0] - 2024-02-20

### Added
- **Project Foundation**
  - Extension scaffolding and structure
  - Basic OAuth flow architecture
  - Initial provider abstraction layer
  - Core service provider setup
- **GitHub OAuth Integration**
  - GitHub OAuth application support
  - User profile and email retrieval
  - Basic scope management for GitHub API
- **Configuration System**
  - Environment variable configuration
  - Provider-specific configuration management
  - Basic validation for OAuth credentials

### Infrastructure
- Extension metadata and composer configuration
- Initial testing framework setup
- Basic development workflow establishment
- Documentation foundation

## [0.14.0] - 2024-01-25

### Added
- Initial project setup and structure
- Basic extension framework integration
- Core dependency injection configuration
- Initial OAuth research and planning

---

## Release Notes

### Version 1.0.0 - Production Ready with Modern Extension System

This is the first stable production release of the Entrada (Social Login & SSO) extension. Version 1.0.0 marks a significant milestone with full migration to Glueful's modern extension architecture:

- **Modern Extension System**: Fully migrated to the new extension architecture as outlined in the [Modern Extensions System](https://github.com/glueful/framework/blob/main/docs/implementation_plans/MODERN_EXTENSIONS_SYSTEM.md)
- **Standardized Structure**: Follows the new extension cookbook guidelines from [Extension Development](https://github.com/glueful/framework/blob/main/docs/cookbook/15-extensions.md)
- **Complete Documentation**: Extensive PHP usage examples and implementation patterns
- **Stable API**: All provider interfaces are stable and production-tested
- **Framework Integration**: Full compatibility with Glueful's latest router and response systems
- **Production Hardened**: All critical bugs fixed and error handling improved
- **Developer Ready**: Comprehensive examples for all common use cases

Key architectural improvements:
- Service provider extends new base classes
- Proper metadata in `extra.glueful` composer configuration
- Standardized directory structure (src/, config/, migrations/, routes/)
- Extension discovery and auto-loading support
- Dependency management and version requirements

The extension is now ready for production deployments with confidence and fully compatible with Glueful's modern extension ecosystem.

### Version 0.18.0 Highlights

This major release establishes the SocialLogin extension as an enterprise-grade OAuth authentication solution. Key improvements include:

- **Complete Multi-Provider Support**: Full implementation for Google, Facebook, GitHub, and Apple
- **Dual Authentication Flows**: Support for both web applications and native mobile apps
- **Advanced Apple Integration**: Custom ASN.1 parser and comprehensive Sign In with Apple support
- **Enterprise Security**: CSRF protection, JWT validation, and secure token management
- **Developer Experience**: Comprehensive API documentation and health monitoring

### Upgrade Notes

When upgrading to 0.18.0:
1. Update your OAuth provider configurations in environment variables
2. Run the database migration to create the social_accounts table
3. Test both web and mobile authentication flows
4. Update your frontend applications to use the new API endpoints
5. Configure Apple Sign In if using iOS applications

### Breaking Changes

- OAuth callback URLs have been standardized (update your provider configurations)
- Database schema now requires the social_accounts table migration
- Some configuration keys have been reorganized for consistency
- API responses now follow a standardized format across all providers

### Migration Guide

#### Database Migration
Run the migration to create the required table:
```bash
php glueful migrate run
```

#### Configuration Migration
Update your environment variables to the new format:

```env
# Google (updated format)
GOOGLE_CLIENT_ID=your-google-client-id.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=https://yourdomain.com/auth/social/google/callback

# Apple (new provider)
APPLE_CLIENT_ID=com.yourdomain.services.id
APPLE_CLIENT_SECRET=/path/to/AuthKey_XXXXXXXXXX.p8
APPLE_TEAM_ID=XXXXXXXXXX
APPLE_KEY_ID=XXXXXXXXXX
APPLE_REDIRECT_URI=https://yourdomain.com/auth/social/apple/callback
```

#### API Integration
Update your frontend code to use the new endpoints:

```javascript
// Web OAuth Flow (unchanged)
window.location.href = '/auth/social/google';

// New: Native Mobile Flow
const response = await fetch('/auth/social/google', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    id_token: googleIdToken,
    access_token: googleAccessToken
  })
});
```

### Apple Sign In Setup

For Apple Sign In, you'll need:
1. Apple Developer account with Services ID configured
2. Private key (.p8 file) for JWT generation
3. Domain verification with Apple
4. Proper redirect URI configuration

### Security Considerations

- All OAuth flows now include CSRF protection
- Provider tokens are validated against official APIs
- JWT tokens use Glueful's secure TokenManager
- Social account data is properly encrypted in storage

### Performance Improvements

- Provider public keys are cached for faster validation
- Connection pooling reduces API call overhead
- Database queries are optimized with proper indexing
- JWT validation results are cached to minimize external requests

---

**Full Changelog**: https://github.com/glueful/extensions/compare/entrada-v0.18.0...entrada-v1.0.0
