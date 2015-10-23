%define version 0
%define release %{build_timestamp}.1%{?dist}
%define build_timestamp %(date --utc +"%Y%m%d%H%M")

Name:           phabricator
Version:        %{version}
Release:        %{release}
Summary:        collection of web applications to help build software

Group:          Web
License:        Apache 2.0
URL:            http://www.phabricator.org
Source0:        https://github.com/phacility/libphutil.git
Source1:        https://github.com/phacility/arcanist.git
Source2:        https://github.com/vinzent/phabricator.git

BuildRequires:  git
BuildArch:      noarch
Requires(pre):  shadow-utils
Requires(post): chkconfig
Requires(preun): chkconfig initscripts
Requires:       git php php-cli php-mysql php-process php-devel php-gd python-pygments
Requires:       php-pecl-apc php-pecl-json php-mbstring sudo
Requires:       phabricator-arcanist = %{version}-%{release}
Requires:       phabricator-libphutil = %{version}-%{release}
AutoReq:        no

%description
Phabricator is an open source collection of web applications which help
software companies build better software.

%package arcanist
Summary:        command-line interface to Phabricator
Requires:       php-cli
Requires:       phabricator-libphutil = %{version}-%{release}
AutoReq:        no

%description arcanist
Arcanists provides command-line access to many Phabricator tools (like
Differential, Files, and Paste), integrates with static analysis ("lint") and
unit tests, and manages common workflows like getting changes into Differential
for review.

%package libphutil
Summary:        a collection of utility classes and functions for PHP
AutoReq:        no

%description libphutil
libphutil is a collection of utility classes and functions for PHP. Some
features of the library include:
- libhutil library system
- futures
- filesystem
- xsprintf
- AAST/PHPAST
- Remarkup
- Daemons
- Utilities

%prep
if [[ ! -e libphutil ]]
then
  git clone https://github.com/phacility/libphutil.git
else
  (cd libphutil && git pull --rebase)
fi

if [[ ! -e arcanist ]]
then
  git clone https://github.com/phacility/arcanist.git
else
  (cd arcanist && git pull --rebase)
fi


if [[ ! -e phabricator ]]
then
  git clone https://github.com/vinzent/phabricator.git
else
  (cd phabricator && git pull --rebase)
fi


%build
echo Nothing to build.

%install
DEST=${RPM_BUILD_ROOT}/opt/phacility
mkdir -p ${DEST}
for dir in libphutil arcanist phabricator; do
  export dir
  ( cd $dir ; git archive --format=tar HEAD ) |
    ( cd ${DEST}; mkdir $dir; cd $dir; tar -x )
  echo "$dir $( cd $dir ; git rev-list -n1 HEAD )" >>${DEST}/GIT-REVS
done

mkdir -p ${RPM_BUILD_ROOT}/var/lib/phabricator
mkdir ${RPM_BUILD_ROOT}/var/lib/phabricator/files
mkdir ${RPM_BUILD_ROOT}/var/lib/phabricator/repo

mkdir -p ${RPM_BUILD_ROOT}%{_initddir}
cp phabricator/resources/rhel/phabricator.init \
  ${RPM_BUILD_ROOT}%{_initddir}/phabricator

mkdir -p ${RPM_BUILD_ROOT}/var/log/phabricator

ln -sf /usr/libexec/git-core/git-http-backend \
  ${DEST}/phabricator/support/bin/git-http-backend

mkdir -p ${RPM_BUILD_ROOT}/etc/sudoers.d
cp phabricator/resources/rhel/phabricator.sudoers \
  ${RPM_BUILD_ROOT}/etc/sudoers.d/phabricator

%clean
rm -rf $RPM_BUILD_ROOT

%pre
getent group phabricator >/dev/null || groupadd -r phabricator
getent passwd phabricator >/dev/null || \
    useradd -r -g phabricator -d /var/lib/phabricator -s /sbin/nologin \
    -c "Daemon user for Phabricator" phabricator

%post
CFG=/opt/phacility/phabricator/bin/config
if ! [ -e /opt/phacility/phabricator/conf/local/local.json ]; then
  $CFG set repository.default-local-path /var/lib/phabricator/repo
  $CFG set storage.local-disk.path /var/lib/phabricator/files
  $CFG set storage.upload-size-limit 10M
  $CFG set pygments.enabled true
  $CFG set phabricator.base-uri http://$(hostname -f)/
  $CFG set metamta.default-address phabricator@$(hostname -f)
  $CFG set metamta.domain $(hostname -f)
  $CFG set phd.user phabricator
  $CFG set phd.log-directory /var/log/phabricator
  $CFG set phd.pid-directory /var/run/phabricator
  $CFG set diffusion.allow-http-auth true
  $CFG set phabricator.csrf-key \
    $(dd if=/dev/urandom bs=128 count=1 2>/dev/null |  base64 | egrep -o '[a-zA-Z0-9]' | head -30 | tr -d '\n')
  $CFG set phabricator.mail-key \
    $(dd if=/dev/urandom bs=128 count=1 2>/dev/null |  base64 | egrep -o '[a-zA-Z0-9]' | head -30 | tr -d '\n')
fi

# Httpd needs access to the repo folder
if ! groupmems -g phabricator -l | grep -q apache; then
  groupmems -g phabricator -a apache
fi

/sbin/chkconfig --add phabricator

%preun
if [ $1 -eq 0 ] ; then
    /sbin/service phabricator stop >/dev/null 2>&1
    /sbin/chkconfig --del phabricator
fi



%files
%defattr(-,root,root,-)
/opt/phacility/phabricator
%attr(0755,-,-) %{_initddir}/phabricator
%attr(0440,-,-) /etc/sudoers.d/phabricator
%dir %attr(0750, phabricator, phabricator)/var/lib/phabricator
%dir %attr(2750, phabricator, phabricator) /var/lib/phabricator/repo
%dir %attr(0700, apache, apache) /var/lib/phabricator/files
%dir %attr(0750, phabricator, phabricator)/var/log/phabricator
/opt/phacility/GIT-REVS

%files arcanist
/opt/phacility/arcanist
/opt/phacility/GIT-REVS

%files libphutil
/opt/phacility/libphutil
/opt/phacility/GIT-REVS


%changelog
