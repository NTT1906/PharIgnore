# PharIgnore
When you create a project that makes heavy use of open libraries, you inevitably end up inundated with useless files, which are then compressed into a phar file and stay there forever.. .
In another example, test files or dev files exist, but you don't want them compressed into a phar file. Given this, this repo handles it sloppily.

```ps1
php -dphar.readonly=0 script\phar.php --in="path/to/Plugin" --out="path/to/pluginFolder"
```

Works almost like .gitignore, you just need to add the name of the unwanted file or its patterns depending on how you want it.

> **Important**
> Hello :><br>