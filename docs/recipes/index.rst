Recipes
=======

Practical, copy-paste solutions for common Webrick use cases. Each recipe is a complete, working example you can adapt to your needs.

--------------

Available Recipes
-----------------

Authentication & Security
~~~~~~~~~~~~~~~~~~~~~~~~~

- `JWT Authentication <./authentication.rst>`__ - Secure API authentication with JWT tokens
- `Rate Limiting <./rate-limiting.rst>`__ - Protect your API from abuse
- `CORS Configuration <./cors.rst>`__ - Cross-origin resource sharing setup

API Development
~~~~~~~~~~~~~~~

- `RESTful API <./api.rst>`__ - Build a complete REST API
- `Webhooks <./webhooks.rst>`__ - Receive and validate webhook payloads
- `Pagination <./pagination.rst>`__ - Efficient data pagination patterns

File Operations
~~~~~~~~~~~~~~~

- `File Uploads <./file-upload.rst>`__ - Handle multipart file uploads securely
- `Download Manager <./file-upload.rst#download-manager>`__ - Serve files with resumable downloads

Data & Search
~~~~~~~~~~~~~

- `Search with Filters <./search.rst>`__ - Build flexible search endpoints
- `Caching Strategies <./caching.rst>`__ - Optimize with smart caching

Operations
~~~~~~~~~~

- `Error Handling <./error-handling.rst>`__ - Centralized error management
- `Logging & Monitoring <./logging.rst>`__ - Telemetry middleware and reusable logging profiles
- `Testing <./testing.rst>`__ - Unit and integration testing strategies

--------------

Recipe Format
-------------

Each recipe includes:

1. **Problem**: What you're trying to solve
2. **Solution**: Complete working code
3. **Explanation**: How it works
4. **Testing**: How to verify it works
5. **Variations**: Alternative approaches

--------------

Contributing Recipes
--------------------

Have a useful pattern? Consider contributing! Recipes should be:

- ✅ Complete and working
- ✅ Production-ready
- ✅ Well-commented
- ✅ Tested

.. toctree::
   :maxdepth: 2
   :hidden:
   :caption: Recipes

   api
   authentication
   caching
   cors
   cors-per-route
   error-handling
   etag-conditional
   file-upload
   head-handling
   logging
   pagination
   rate-limiting
   search
   streaming-downloads
   testing
   throttling
   trailing-slash
   webhooks
