#!/bin/bash

cd ..

zip -r version-tracker.zip version-tracker --exclude="version-tracker/.git/*" --exclude="version-tracker/.idea/*" --exclude="version-tracker/.gitignore"
mv version-tracker.zip version-tracker

cd -