#!/usr/bin/env ruby

require 'json'
require 'yaml'

ROOT = File.expand_path('../..', __dir__)

def assert(condition, message)
  raise message unless condition
end

def workflow(name)
  document = YAML.safe_load(
    File.read(File.join(ROOT, '.github', 'workflows', name)),
    aliases: true,
  )
  document['on'] ||= document[true]
  document
end

def step(job, name)
  job.fetch('steps').find { |candidate| candidate['name'] == name }
end

def validate_container_workflow(filename, prefix)
  document = workflow(filename)
  triggers = document.fetch('on')
  jobs = document.fetch('jobs')
  publish = jobs.fetch('publish')
  publish_steps = publish.fetch('steps')

  assert(triggers.fetch('push').fetch('tags') == ["#{prefix}*"], "#{filename}: wrong tag trigger")
  assert(triggers.key?('workflow_dispatch'), "#{filename}: workflow_dispatch missing")
  assert(
    jobs.fetch('release-gate').fetch('if') ==
      "github.event_name == 'push' && startsWith(github.ref, 'refs/tags/#{prefix}')",
    "#{filename}: release gate must be push-only",
  )
  assert(
    jobs.fetch('release-build').fetch('needs') == 'release-quality',
    "#{filename}: tagged build must wait for quality",
  )
  assert(
    jobs.fetch('build').fetch('if').include?("!(github.event_name == 'push'"),
    "#{filename}: normal build must exclude tag pushes",
  )
  assert(
    publish.fetch('if').include?("github.event_name == 'push'"),
    "#{filename}: publish must be push-only",
  )

  tip_check_index = publish_steps.index { |candidate| candidate['name'] == 'Verify current main tip before publishing latest' }
  publish_index = publish_steps.index { |candidate| candidate['name'] == 'Build and push Docker image' }
  assert(tip_check_index == publish_index - 1, "#{filename}: main tip check must immediately precede publish")
  assert(
    publish_steps[tip_check_index].fetch('run').include?('GITHUB_SHA') &&
      publish_steps[tip_check_index].fetch('run').include?('origin/main'),
    "#{filename}: main tip check is incomplete",
  )

  metadata = step(publish, 'Extract image metadata').fetch('with').fetch('tags')
  assert(
    metadata.include?("enable=${{ github.event_name == 'push' && github.ref == 'refs/heads/main' }}"),
    "#{filename}: latest is not restricted to main pushes",
  )
  assert(
    jobs.fetch('release').fetch('if') ==
      "github.event_name == 'push' && startsWith(github.ref, 'refs/tags/#{prefix}')",
    "#{filename}: GitHub Release must be push-only",
  )

  gate_script = step(jobs.fetch('release-gate'), 'Verify tag commit belongs to main').fetch('run')
  assert(gate_script.include?('GITHUB_SHA'), "#{filename}: tag commit is not checked")
  assert(gate_script.include?('git rev-parse origin/main'), "#{filename}: every release tag must target main tip")

end

def container_policy(component:, event:, ref:, current_main_tip: true)
  prefix = component == :backend ? 'backend-v' : 'client-v'
  tag = ref.start_with?("refs/tags/#{prefix}")
  main = ref == 'refs/heads/main'
  dev = ref == 'refs/heads/dev'
  push = event == :push
  publish = push && ((main && current_main_tip) || (component == :client && dev) || (tag && current_main_tip))

  {
    triggered: true,
    publish: publish,
    latest: publish && main,
    release: push && tag && current_main_tip,
  }
end

def mobile_policy(event:, ref:, current_main_tip: true)
  push = event == :push
  tag = ref.start_with?('refs/tags/mobile-v')
  blocked = push && tag && !current_main_tip
  store = push && tag && current_main_tip

  {
    triggered: true,
    android_profile: blocked ? :blocked : (store ? :store : :preview),
    ios_profile: blocked ? :blocked : (store ? :store : :ios_simulator),
    submit: store,
    release: push && tag && current_main_tip,
  }
end

def mobile_concurrency(event:, ref:)
  store = event == :push && ref.start_with?('refs/tags/mobile-v')
  {
    group: store ? 'mobile-store' : "mobile-preview-#{ref}",
    cancel_in_progress: false,
  }
end

def validate_event_matrix
  cases = [
    [:pull_request, 'refs/pull/10/merge', true, false, false, false],
    [:push, 'refs/heads/dev', true, false, false, false],
    [:push, 'refs/heads/main', true, true, true, false],
    [:push, 'refs/heads/main', false, false, false, false],
    [:push, 'refs/tags/backend-v1.0.0-beta.1', true, true, false, true],
    [:push, 'refs/tags/backend-v1.0.0-beta.1', false, false, false, false],
    [:workflow_dispatch, 'refs/tags/backend-v1.0.0-beta.1', true, false, false, false],
  ]

  cases.each do |event, ref, tip, backend_publish, backend_latest, backend_release|
    result = container_policy(component: :backend, event: event, ref: ref, current_main_tip: tip)
    assert(result[:publish] == backend_publish, "backend matrix failed for #{event} #{ref}")
    assert(result[:latest] == backend_latest, "backend latest matrix failed for #{event} #{ref}")
    assert(result[:release] == backend_release, "backend release matrix failed for #{event} #{ref}")
  end

  client_dev = container_policy(component: :client, event: :push, ref: 'refs/heads/dev')
  assert(client_dev == { triggered: true, publish: true, latest: false, release: false }, 'client dev matrix failed')
  client_tag = container_policy(
    component: :client,
    event: :push,
    ref: 'refs/tags/client-v1.0.0-beta.1',
  )
  assert(client_tag == { triggered: true, publish: true, latest: false, release: true }, 'client tag matrix failed')
  client_manual = container_policy(
    component: :client,
    event: :workflow_dispatch,
    ref: 'refs/heads/main',
  )
  assert(client_manual == { triggered: true, publish: false, latest: false, release: false }, 'client manual matrix failed')

  mobile_cases = [
    [:pull_request, 'refs/pull/10/merge', true, true, :preview, :ios_simulator, false, false],
    [:push, 'refs/heads/dev', true, true, :preview, :ios_simulator, false, false],
    [:push, 'refs/heads/main', true, true, :preview, :ios_simulator, false, false],
    [:push, 'refs/heads/main', false, true, :preview, :ios_simulator, false, false],
    [:push, 'refs/tags/mobile-v1.0.0-beta.1', true, true, :store, :store, true, true],
    [:push, 'refs/tags/mobile-v1.0.0-beta.1', false, true, :blocked, :blocked, false, false],
    [:workflow_dispatch, 'refs/tags/mobile-v1.0.0-beta.1', true, true, :preview, :ios_simulator, false, false],
  ]
  mobile_cases.each do |event, ref, tip, triggered, android_profile, ios_profile, submit, release|
    result = mobile_policy(event: event, ref: ref, current_main_tip: tip)
    expected = {
      triggered: triggered,
      android_profile: android_profile,
      ios_profile: ios_profile,
      submit: submit,
      release: release,
    }
    assert(result == expected, "mobile matrix failed for #{event} #{ref}")
  end

  running_tag = mobile_concurrency(event: :push, ref: 'refs/tags/mobile-v1.0.0-beta.1')
  arriving_tag = mobile_concurrency(event: :push, ref: 'refs/tags/mobile-v1.0.1-beta.1')
  arriving_main = mobile_concurrency(event: :push, ref: 'refs/heads/main')
  assert(running_tag[:group] == arriving_tag[:group], 'mobile store tag runs must serialize globally')
  assert(running_tag[:group] != arriving_main[:group], 'main preview runs must not use store concurrency')
  assert(running_tag[:cancel_in_progress] == false, 'a running tag release must not be canceled')
  assert(arriving_tag[:cancel_in_progress] == false, 'a later tag must not cancel a running tag release')
end

def validate_mobile_workflow
  document = workflow('mobile-app-build.yml')
  triggers = document.fetch('on')
  jobs = document.fetch('jobs')
  concurrency = document.fetch('concurrency')

  assert(triggers.key?('pull_request'), 'mobile workflow must run for pull requests')
  assert(triggers.fetch('push').fetch('branches') == %w[main dev], 'mobile workflow has wrong branch triggers')
  assert(triggers.fetch('push').fetch('tags') == ['mobile-v*'], 'mobile workflow has wrong tag trigger')
  assert(triggers.key?('workflow_dispatch'), 'mobile workflow must support manual preview builds')
  expected_group = "${{ github.event_name == 'push' && startsWith(github.ref, 'refs/tags/mobile-v') && 'mobile-store' || format('mobile-preview-{0}', github.ref) }}"
  assert(concurrency.fetch('group') == expected_group, 'mobile workflow must serialize complete store runs')
  assert(concurrency.fetch('cancel-in-progress') == false, 'mobile store runs must never cancel in-progress releases')

  %w[release-gate release-quality].each do |job_name|
    assert(
      jobs.fetch(job_name).fetch('if') ==
        "github.event_name == 'push' && startsWith(github.ref, 'refs/tags/mobile-v')",
      "mobile #{job_name} must be push-only",
    )
  end
  release = jobs.fetch('release')
  assert(
    release.fetch('if') == "github.event_name == 'push' && startsWith(github.ref, 'refs/tags/mobile-v')",
    'mobile GitHub Release must be tag-push-only',
  )
  assert(release.fetch('needs') == %w[release-quality android ios], 'mobile release has wrong dependencies')
  gate_script = step(jobs.fetch('release-gate'), 'Verify tag commit belongs to main').fetch('run')
  assert(gate_script.include?('git rev-parse origin/main'), 'mobile release tag must target the current main tip')

  %w[android ios].each do |job_name|
    job = jobs.fetch(job_name)
    assert(!job.key?('concurrency'), "#{job_name}: concurrency must protect the complete workflow")
    distribution = step(job, "Select #{job_name == 'ios' ? 'iOS' : 'Android'} distribution")
    assert(distribution.fetch('run').include?('GITHUB_EVENT_NAME'), "#{job_name}: manual dispatch is not preview-only")
    assert(!distribution.fetch('run').include?('refs/heads/main'), "#{job_name}: main must not select the store profile")
    expected_preview_profile = job_name == 'ios' ? 'profile=ios-simulator' : 'profile=preview'
    assert(distribution.fetch('run').include?(expected_preview_profile), "#{job_name}: wrong non-tag profile")
    expected_preview_artifact =
      job_name == 'ios' ? 'artifact=openlocker-ios-simulator.tar.gz' : 'artifact=openlocker-android.apk'
    assert(distribution.fetch('run').include?(expected_preview_artifact), "#{job_name}: wrong non-tag artifact")
    expected_store_artifact = job_name == 'ios' ? 'artifact=openlocker-ios.ipa' : 'artifact=openlocker-android.aab'
    assert(distribution.fetch('run').include?(expected_store_artifact), "#{job_name}: wrong store artifact")
    assert(distribution.fetch('run').scan(/>> "\$\{GITHUB_OUTPUT\}"/).length == 2, "#{job_name}: outputs must be grouped per branch")
    version = step(job, 'Derive tagged app version')
    assert(version.fetch('if').start_with?("github.event_name == 'push'"), "#{job_name}: tag version must be push-only")

    submit_name = job_name == 'ios' ? 'Submit TestFlight candidate' : 'Submit Android store candidate'
    submit = step(job, submit_name)
    expected_submit_if = "github.event_name == 'push' && startsWith(github.ref, 'refs/tags/mobile-v')"
    assert(submit.fetch('if') == expected_submit_if, "#{job_name}: submit expression is not push-only")
    assert(submit.fetch('run').include?('pnpm dlx "eas-cli@${EAS_CLI_VERSION}" submit'), "#{job_name}: EAS submit package must be quoted")
    build = step(job, "Build #{job_name == 'ios' ? 'iOS' : 'Android'} (${{ steps.distribution.outputs.channel }})")
    assert(build.fetch('run').include?('pnpm dlx "eas-cli@${EAS_CLI_VERSION}" build'), "#{job_name}: EAS build package must be quoted")
    assert(
      build.fetch('run').include?('--output "./build/${{ steps.distribution.outputs.artifact }}"'),
      "#{job_name}: build output must follow the selected artifact",
    )
  end
end

def validate_mobile_profiles
  eas = JSON.parse(File.read(File.join(ROOT, 'mobile-app', 'eas.json')))
  profiles = eas.fetch('build')
  simulator = profiles.fetch('ios-simulator')

  assert(simulator.fetch('extends') == 'preview', 'iOS simulator profile must inherit preview settings')
  assert(simulator.fetch('ios').fetch('simulator') == true, 'iOS simulator profile must disable device signing')
  assert(profiles.fetch('store').fetch('extends') == 'production', 'store profile mapping changed unexpectedly')
end

def validate_release_workflow
  release = workflow('component-release.yml').fetch('jobs').fetch('release')
  notes_step = step(release, 'Generate component release notes')
  assert(notes_step.fetch('with').fetch('args').start_with?('--current '), 'release notes must use the checked-out tag')
  create_step = step(release, 'Create GitHub Release')
  script = create_step.fetch('run')
  assert(script.index('gh release view') < script.index('gh release create'), 'release idempotency check must run first')
  assert(script.include?('--json tagName,name,body,isDraft,isPrerelease'), 'release validation must read title and notes')
  assert(script.include?('.name == $title'), 'release validation must compare the title')
  assert(script.include?('.body | rtrimstr'), 'release validation must compare the notes')
  assert(script.include?('--verify-tag'), 'release creation must verify the existing tag')
end

def validate_git_cliff
  expected = {
    'backend' => ['^backend-v', 'include_paths = ["locker-backend/**", "docs/asyncapi/**"]'],
    'client' => ['^client-v', 'include_paths = ["locker-client/**", "docs/asyncapi/**"]'],
    'mobile' => ['^mobile-v', 'include_paths = ["mobile-app/**"]'],
  }

  expected.each do |component, (tag_pattern, include_paths)|
    config = File.read(File.join(ROOT, '.github', 'git-cliff', "#{component}.toml"))
    assert(config.include?("tag_pattern = \"#{tag_pattern}"), "#{component}: wrong tag pattern")
    assert(config.include?(include_paths), "#{component}: wrong release-note paths")
  end
end

def validate_documentation_filters(filename, component)
  triggers = workflow(filename).fetch('on')
  expected_exclusions = [
    "!#{component}/**/*.md",
    "!#{component}/.cursor/**",
  ]

  %w[pull_request push].each do |event|
    paths = triggers.fetch(event).fetch('paths')
    expected_exclusions.each do |exclusion|
      assert(paths.include?(exclusion), "#{filename}: #{event} must exclude #{exclusion}")
    end
  end
end

validate_container_workflow('backend-docker.yml', 'backend-v')
validate_container_workflow('client-docker.yml', 'client-v')
validate_mobile_workflow
validate_mobile_profiles
validate_release_workflow
validate_git_cliff
validate_event_matrix
validate_documentation_filters('backend-docker.yml', 'locker-backend')
validate_documentation_filters('mqtt-contract-ci.yml', 'locker-backend')
validate_documentation_filters('client-docker.yml', 'locker-client')
validate_documentation_filters('locker-client-ci.yml', 'locker-client')
validate_documentation_filters('mobile-app-build.yml', 'mobile-app')
validate_documentation_filters('mobile-app-ci.yml', 'mobile-app')

package = JSON.parse(File.read(File.join(ROOT, 'locker-client', 'package.json')))
assert(package.fetch('version') == '1.0.0', 'locker client release version must be 1.0.0')

puts 'Release workflow structure and event matrix are valid.'
