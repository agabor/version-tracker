#!/bin/bash

rm version-tracker.zip

cd ..

zip -r version-tracker.zip version-tracker --exclude="version-tracker/.git/*" --exclude="version-tracker/.idea/*" --exclude="version-tracker/.gitignore" --exclude="version-tracker/*.sh"
mv version-tracker.zip version-tracker

cd -