# Trailing-slash normalization

Use `autoSlashRedirect` when you want Webrick to normalize slash variants at registrar level.

```php
$kernel = RouterKernel::bootWithRegistrar(
    // ...
    registrarOptions: [
        'autoSlashRedirect' => true,
    ],
);
```

If you need custom behavior, add a `preGlobal` middleware that redirects `/path/` to `/path` and then register routes without trailing slashes.
