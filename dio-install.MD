# Instruction to install PHP Direct IO on Archlinux

https://pecl.php.net/package/dio

```bash
git clone https://aur.archlinux.org/php-pear.git
gpg --keyserver hkp://keyserver.ubuntu.com --recv-keys "10F68CF73F8AA426F617752972A321BAC245F175"
makepkg -si

pecl install channel://pecl.php.net/dio-0.2.0
echo "extension=dio.so" >> /etc/php/php.ini
```