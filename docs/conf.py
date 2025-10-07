project = "Webrick – PHP Router"
author = "Infocyph"
version = "0.1"
release = "0.1"

extensions = [
    "myst_parser",
    "sphinx.ext.autodoc",
    "sphinx.ext.autosectionlabel",
    "sphinx.ext.napoleon",
    "sphinx.ext.intersphinx",
    "sphinx_copybutton",
    "sphinx_design",
]

myst_enable_extensions = [
    "colon_fence",
    "deflist",
    "attrs_block",
    "attrs_inline",
    "tasklist",
    "fieldlist",
]

templates_path = ["_templates"]
exclude_patterns = ["_build", "Thumbs.db", ".DS_Store"]

html_theme = "sphinx_rtd_theme"
html_theme_options = {
    "collapse_navigation": False,
    "sticky_navigation": True,
    "navigation_depth": 3,
    "logo_only": False,
    "style_external_links": True,
}

html_static_path = ["_static"]
html_css_files = ["theme.css"]

intersphinx_mapping = {
    "python": ("https://docs.python.org/3", {}),
    "php": ("https://www.php.net/manual/en", {}),
}

autodoc_default_options = {
    "members": True,
    "undoc-members": True,
    "show-inheritance": True,
}
napoleon_google_docstring = True
napoleon_numpy_docstring = False

html_show_sourcelink = True
html_show_sphinx = False
