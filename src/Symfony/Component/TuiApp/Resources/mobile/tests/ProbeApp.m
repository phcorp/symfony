#import <UIKit/UIKit.h>
int tui_mobile_probe(const char *path);

@interface ProbeScene : UIResponder <UIWindowSceneDelegate>
@property(strong, nonatomic) UIWindow *window;
@end
@implementation ProbeScene
- (void)scene:(UIScene *)scene willConnectToSession:(UISceneSession *)session options:(UISceneConnectionOptions *)options
{
    self.window = [[UIWindow alloc] initWithWindowScene:(UIWindowScene *)scene];
    UIViewController *controller = [UIViewController new];
    controller.view.backgroundColor = UIColor.systemBackgroundColor;
    UILabel *label = [[UILabel alloc] initWithFrame:CGRectMake(20, 100, 360, 120)];
    label.numberOfLines = 0;
    label.text = @"Checking embedded PHP…";
    [controller.view addSubview:label];
    self.window.rootViewController = controller;
    [self.window makeKeyAndVisible];
    NSString *entry = [NSBundle.mainBundle pathForResource:@"probe" ofType:@"php"];
    dispatch_async(dispatch_get_global_queue(QOS_CLASS_USER_INITIATED, 0), ^{
        int status = tui_mobile_probe(entry.fileSystemRepresentation);
        NSString *receipt = [NSString stringWithFormat:@"TUI_MOBILE_PROBE=%d\n", status];
        NSString *directory = NSSearchPathForDirectoriesInDomains(NSDocumentDirectory, NSUserDomainMask, YES).firstObject;
        [receipt writeToFile:[directory stringByAppendingPathComponent:@"probe.txt"] atomically:YES encoding:NSUTF8StringEncoding error:nil];
        dispatch_async(dispatch_get_main_queue(), ^{ label.text = receipt; });
    });
}
@end
@interface ProbeDelegate : UIResponder <UIApplicationDelegate>
@end
@implementation ProbeDelegate
- (UISceneConfiguration *)application:(UIApplication *)application configurationForConnectingSceneSession:(UISceneSession *)session options:(UISceneConnectionOptions *)options
{
    UISceneConfiguration *configuration = [[UISceneConfiguration alloc] initWithName:@"Probe" sessionRole:session.role];
    configuration.delegateClass = ProbeScene.class;
    return configuration;
}
@end
int main(int argc, char **argv)
{
    @autoreleasepool { return UIApplicationMain(argc, argv, nil, NSStringFromClass(ProbeDelegate.class)); }
}
