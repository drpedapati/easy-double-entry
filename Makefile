# Packaging for REDCap External Module hand-off.
#
#   make package                  build dist/easy_double_entry_v<VERSION>.zip + sha256
#   make package VERSION=1.2.0    override the version
#   make clean                    remove dist/
#
# The zip unpacks to easy_double_entry_v<VERSION>/ — the exact directory name
# REDCap expects under modules/. Built from committed content (git archive),
# so commit before packaging.

VERSION := 1.1.0
MODULE  := easy_double_entry
DIST    := dist
PKG     := $(DIST)/$(MODULE)_v$(VERSION).zip

.PHONY: package clean

package:
	@git diff --quiet HEAD -- . ':!dist' || echo "WARNING: uncommitted changes are NOT included (git archive packages HEAD)"
	mkdir -p $(DIST)
	git archive --format=zip --prefix=$(MODULE)_v$(VERSION)/ -o $(PKG) HEAD
	shasum -a 256 $(PKG) | tee $(PKG).sha256
	@unzip -l $(PKG) | tail -1
	@echo "Package ready: $(PKG)"

clean:
	rm -rf $(DIST)
