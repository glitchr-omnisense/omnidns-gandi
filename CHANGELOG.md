# Changelog

## [Unreleased]

### Changed
- Each page of a listing counted: `domains()` fetch the first page as before (the registry's guard
  counts the operation) and each further page through the provider's guard (`Config::guard()`,
  from the factory, the constructor's last argument `pages`), checked before it is sent and
  counted after under the same operation, in the registry's limiter: a limit reached stops the
  listing before the next page is requested. Needs omnisense/contracts 16c8da1 or later
  (`Config::guard()`; 1568f2c, what the sites' vendors hold, has it).
