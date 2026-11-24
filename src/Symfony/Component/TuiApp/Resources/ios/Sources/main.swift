import UIKit

final class SceneDelegate: UIResponder, UIWindowSceneDelegate {
  var window: UIWindow?
  func sceneDidEnterBackground(_ scene: UIScene) {
    HostController.shared.session?.setForeground(false)
  }
  func sceneWillEnterForeground(_ scene: UIScene) {
    HostController.shared.session?.setForeground(true)
  }
  func scene(
    _ scene: UIScene, willConnectTo session: UISceneSession, options: UIScene.ConnectionOptions
  ) {
    guard let scene = scene as? UIWindowScene else { return }
    let window = UIWindow(windowScene: scene)
    window.rootViewController = HostController.shared
    self.window = window
    window.makeKeyAndVisible()
  }
}
final class AppDelegate: UIResponder, UIApplicationDelegate {
  func application(
    _ application: UIApplication, configurationForConnecting session: UISceneSession,
    options: UIScene.ConnectionOptions
  ) -> UISceneConfiguration {
    let configuration = UISceneConfiguration(name: "TuiApp", sessionRole: session.role)
    configuration.delegateClass = SceneDelegate.self
    return configuration
  }
}
UIApplicationMain(
  CommandLine.argc, CommandLine.unsafeArgv, nil, NSStringFromClass(AppDelegate.self))
