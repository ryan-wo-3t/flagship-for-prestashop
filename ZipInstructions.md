# Packaging FlagShip Module

When you need to hand over a build to the user (or run our validator), always create the module zip exactly like the working versions in this history. Follow these steps:

1. **Verify the tree:** `git status -sb` should only show intentional changes. Run tests or linting if required.
2. **Create the archive from git** (this keeps the structure PrestaShop expects and automatically includes tracked vendor files):

   ```powershell
   git archive --format=zip --prefix=flagshipshipping/ HEAD -o flagshipshipping-1.0.270.zip
   Copy-Item -Force flagshipshipping-1.0.270.zip flagshipshipping.zip
   ```

   Replace the version in the filename if the user asks for a bump.

3. **Sanity check** (optional but recommended while debugging installs):

   ```powershell
   php checkzip.php
   ```

   It should list a single `flagshipshipping` folder and report `true`.

4. **Deliver the zip**: share whichever filename the user prefers. Both zips are identical.

Never try to manually assemble the module by copying individual files into a `build/` folder unless specifically instructed—`git archive` keeps things consistent and prevents missing dependencies.
